<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\BedHistory;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionWalkinDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $locationId = $user?->location_id;
        $timezone = 'Asia/Manila';
        $startOfDay = Carbon::now($timezone)->startOfDay();
        $endOfDay = Carbon::now($timezone)->endOfDay();

        $transactions = Transaction::query()
            ->where('location_id', $locationId);

        $beds = Bed::query()->whereHas(
            'room',
            fn ($roomQuery) => $roomQuery->where('location_id', $locationId)
        );

        // Earnings are recorded on detail rows (login, extend, switch, penalty).
        // Summing transactions.created_at misses same-day extends on older stays.
        $transactionEarnings = (float) TransactionDetail::query()
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', [1, 3, 4, 5])
            ->sum('amount');

        $walkInEarnings = (float) TransactionWalkinDetail::query()
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereIn('status', [1, 3])
            ->sum('amount');

        $allEarnings = $transactionEarnings + $walkInEarnings;

        $totalOccupants = (clone $transactions)->where('status', 1)->count();
        $availableBeds = (clone $beds)->where('status', 1)->count();
        $location = $locationId ? Location::find($locationId) : null;

        return $this->success([
            'location_id' => $locationId,
            'location_name' => $location?->name,
            'all_earnings' => round($allEarnings, 2),
            'total_occupants' => $totalOccupants,
            'available_beds' => $availableBeds,
            'earnings_date' => $startOfDay->toDateString(),
        ]);
    }

    public function transactionDetails(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;
        $timezone = 'Asia/Manila';
        $today = Carbon::now($timezone)->toDateString();

        $filters = $request->validate([
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $start = Carbon::parse($filters['date_start'] ?? $today, $timezone)->startOfDay();
        $end = Carbon::parse($filters['date_end'] ?? $today, $timezone)->endOfDay();

        if ($start->gt($end)) {
            return $this->error('Date Start must be on or before Date End.', 422);
        }

        $query = TransactionDetail::query()
            ->with([
                'transaction.customer',
                'transaction.bed.room',
            ])
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->whereBetween('created_at', [$start, $end])
            ->when($filters['search'] ?? null, function ($builder) use ($filters) {
                $search = $filters['search'];

                $builder->where(function ($searchQuery) use ($search) {
                    $searchQuery->whereHas('transaction.customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%");
                    })->orWhereHas('transaction.bed', function ($bedQuery) use ($search) {
                        $bedQuery->where('name', 'like', "%{$search}%")
                            ->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('name', 'like', "%{$search}%"));
                    });
                });
            })
            ->latest();

        $paginator = $query->paginate($this->perPage($request));
        $bedHistories = BedHistory::query()
            ->whereIn('transaction_detail_id', collect($paginator->items())->pluck('id'))
            ->with('bed.room')
            ->get()
            ->keyBy('transaction_detail_id');

        $paginator->getCollection()->transform(
            fn (TransactionDetail $detail) => $this->formatTransactionDetailRow($detail, $bedHistories)
        );

        return $this->paginatedResponse($paginator);
    }

    private function formatTransactionDetailRow(TransactionDetail $detail, $bedHistories): array
    {
        $transaction = $detail->transaction;
        $customer = $transaction?->customer;
        $customerName = $customer
            ? strtoupper(trim(($customer->last_name ?? '') . ', ' . trim(($customer->first_name ?? '') . ' ' . ($customer->middle_name ?? ''))))
            : '—';

        $bedHistory = $bedHistories->get($detail->id);
        $bed = $bedHistory?->bed ?? $transaction?->bed;
        $status = (int) $detail->status;

        return [
            'id' => $detail->id,
            'customer_name' => $customerName,
            'status' => $status,
            'status_label' => $this->detailStatusLabel($status),
            'applied' => $this->detailAppliedValue($detail, $status),
            'penalty' => $this->detailPenaltyValue($detail, $status),
            'amount' => (float) ($detail->amount ?? 0),
            'room_name' => $bed?->room?->name,
            'bed_name' => $bed?->name,
            'created_at' => $detail->created_at,
        ];
    }

    private function detailStatusLabel(int $status): string
    {
        return match ($status) {
            1 => 'Login',
            2 => 'Logout',
            3 => 'Extend',
            4 => 'Switch',
            5 => 'Penalty',
            default => 'Unknown',
        };
    }

    private function detailAppliedValue(TransactionDetail $detail, int $status): int
    {
        return match ($status) {
            1, 3 => (int) ($detail->extend_days ?? 0),
            2 => (int) ($detail->transaction?->consumed_day ?? 0),
            4, 5 => (int) ($detail->penalty_days ?? 0),
            default => 0,
        };
    }

    private function detailPenaltyValue(TransactionDetail $detail, int $status): int
    {
        return match ($status) {
            2 => (int) ($detail->transaction?->penalty_day ?? 0),
            5 => (int) ($detail->penalty_days ?? 0),
            default => 0,
        };
    }

    public function walkinTransactionDetails(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;
        $timezone = 'Asia/Manila';
        $today = Carbon::now($timezone)->toDateString();

        $filters = $request->validate([
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $start = Carbon::parse($filters['date_start'] ?? $today, $timezone)->startOfDay();
        $end = Carbon::parse($filters['date_end'] ?? $today, $timezone)->endOfDay();

        if ($start->gt($end)) {
            return $this->error('Date Start must be on or before Date End.', 422);
        }

        $query = TransactionWalkinDetail::query()
            ->with(['transaction.customer'])
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->whereBetween('created_at', [$start, $end])
            ->when($filters['search'] ?? null, function ($builder) use ($filters) {
                $search = $filters['search'];

                $builder->whereHas('transaction.customer', function ($customerQuery) use ($search) {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('id_presented', 'like', "%{$search}%");
                });
            })
            ->latest();

        $paginator = $query->paginate($this->perPage($request));
        $paginator->getCollection()->transform(
            fn (TransactionWalkinDetail $detail) => $this->formatWalkinDetailRow($detail)
        );

        return $this->paginatedResponse($paginator);
    }

    private function formatWalkinDetailRow(TransactionWalkinDetail $detail): array
    {
        $status = (int) $detail->status;

        return [
            'id' => $detail->id,
            'customer_name' => strtoupper(trim($detail->transaction?->customer?->name ?? '—')),
            'status' => $status,
            'status_label' => $this->walkinDetailStatusLabel($status),
            'hours' => (int) ($detail->hours ?? 0),
            'amount' => (float) ($detail->amount ?? 0),
            'created_at' => $detail->created_at,
        ];
    }

    private function walkinDetailStatusLabel(int $status): string
    {
        return match ($status) {
            1 => 'Login',
            2 => 'Logout',
            3 => 'Extend',
            default => 'Unknown',
        };
    }
}
