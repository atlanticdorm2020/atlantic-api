<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\CustomerWalkin;
use App\Models\CustomRate;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\TransactionWalkin;
use App\Services\TransactionWalkinCheckoutService;
use App\Services\TransactionWalkinExtendService;
use App\Services\TransactionWalkinStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TransactionWalkinController extends Controller
{
    private const TRANSACTION_TYPE_LABELS = [
        0 => 'With Agent',
        1 => 'Walk In Couple',
    ];

    private function transactionTypeOptions(): \Illuminate\Support\Collection
    {
        return collect([
            ['value' => 1, 'label' => self::TRANSACTION_TYPE_LABELS[1]],
            ['value' => 0, 'label' => self::TRANSACTION_TYPE_LABELS[0]],
        ]);
    }

    public function formOptions(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;
        $search = $request->filled('search') ? $request->string('search') : null;

        $customersQuery = CustomerWalkin::query()
            ->where('status', 0)
            ->when($search, function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('id_presented', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $customers = $customersQuery
            ->limit($search ? 50 : 500)
            ->get(['id', 'name', 'id_presented'])
            ->map(fn (CustomerWalkin $customer) => [
                'id' => $customer->id,
                'label' => strtoupper(trim($customer->name)),
                'id_presented' => $customer->id_presented,
            ])
            ->values();

        $rooms = Room::query()
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('status', 1)
            ->where('is_bedspace', 0)
            ->orderBy('ordered')
            ->get(['id', 'name', 'rate_id'])
            ->values();

        $transactionTypes = $this->transactionTypeOptions();

        return $this->success([
            'customers' => $customers,
            'customers_total' => $search
                ? $customers->count()
                : CustomerWalkin::where('status', 0)->count(),
            'rooms' => $rooms,
            'transaction_types' => $transactionTypes,
        ]);
    }

    private function formatCustomRateLabel(CustomRate $customRate): string
    {
        $amount = number_format((float) ($customRate->rate?->amount ?? 0), 2, '.', '');

        return sprintf('%d hours (%s)', (int) $customRate->hours, $amount);
    }

    private function mapCustomRates($locationId, int $transactionType)
    {
        return CustomRate::query()
            ->with('rate')
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('type', $transactionType)
            ->where('status', 1)
            ->orderBy('hours')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomRate $customRate) => [
                'id' => $customRate->id,
                'hours' => $customRate->hours,
                'amount' => (float) ($customRate->rate?->amount ?? 0),
                'label' => $this->formatCustomRateLabel($customRate),
            ])
            ->values();
    }

    public function formPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'transaction_type' => ['nullable', 'integer', 'in:0,1'],
        ]);

        $locationId = $request->user()?->location_id;
        $room = Room::query()
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('is_bedspace', 0)
            ->findOrFail($data['room_id']);

        $occupiedBedIds = TransactionWalkin::query()
            ->where('status', 1)
            ->where('room_id', $room->id)
            ->pluck('bed_id')
            ->merge(
                Transaction::query()
                    ->where('status', '1')
                    ->whereHas('bed', fn ($builder) => $builder->where('room_id', $room->id))
                    ->pluck('bed_id')
            )
            ->unique()
            ->values();

        $beds = Bed::query()
            ->where('room_id', $room->id)
            ->where('status', 1)
            ->whereNotIn('id', $occupiedBedIds)
            ->orderBy('sort')
            ->get(['id', 'name'])
            ->values();

        $rates = collect();

        if (array_key_exists('transaction_type', $data) && $data['transaction_type'] !== null) {
            $rates = $this->mapCustomRates($locationId, (int) $data['transaction_type']);
        }

        return $this->success([
            'beds' => $beds,
            'rates' => $rates,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;

        $query = TransactionWalkin::query()
            ->with(['customer', 'room', 'bed'])
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('status', 1)
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($inner) use ($search) {
                    $inner->where('unique_id', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('id_presented', 'like', "%{$search}%");
                        })
                        ->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('login');

        $paginator = $query->paginate($this->perPage($request));
        $paginator->getCollection()->transform(
            fn (TransactionWalkin $transaction) => $this->formatWalkinTransaction($transaction)
        );

        return $this->paginatedResponse($paginator);
    }

    public function store(Request $request, TransactionWalkinStoreService $storeService): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customer_walkin,id'],
            'bed_id' => ['required', 'integer', 'exists:beds,id'],
            'transaction_type' => ['required', 'integer', 'in:0,1'],
            'custom_rate_id' => ['required', 'integer', 'exists:custom_rates,id'],
            'login' => ['nullable', 'date'],
        ]);

        $locationId = (int) ($request->user()?->location_id ?? 0);
        $userId = (int) ($request->user()?->id ?? 0);

        if (! $locationId || ! $userId) {
            return $this->error('Branch location is required.', 422);
        }

        try {
            $transaction = $storeService->create($data, $locationId, $userId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            $this->formatWalkinTransaction($transaction),
            'Walk-in transaction created.',
            201
        );
    }

    public function rateOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_type' => ['required', 'integer', 'in:0,1'],
        ]);

        $locationId = $request->user()?->location_id;

        return $this->success([
            'rates' => $this->mapCustomRates($locationId, (int) $data['transaction_type']),
        ]);
    }

    public function checkout(
        Request $request,
        TransactionWalkin $transactionWalkin,
        TransactionWalkinCheckoutService $checkoutService,
    ): JsonResponse {
        $locationId = (int) ($request->user()?->location_id ?? 0);

        if (! $locationId) {
            return $this->error('Branch location is required.', 422);
        }

        try {
            $transaction = $checkoutService->checkout($transactionWalkin, $locationId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            $this->formatWalkinTransaction($transaction),
            'Walk-in guest checked out successfully.'
        );
    }

    public function extend(
        Request $request,
        TransactionWalkin $transactionWalkin,
        TransactionWalkinExtendService $extendService,
    ): JsonResponse {
        $data = $request->validate([
            'custom_rate_id' => ['required', 'integer', 'exists:custom_rates,id'],
        ]);

        $locationId = (int) ($request->user()?->location_id ?? 0);

        if (! $locationId) {
            return $this->error('Branch location is required.', 422);
        }

        try {
            $transaction = $extendService->extend($transactionWalkin, (int) $data['custom_rate_id'], $locationId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            $this->formatWalkinTransaction($transaction),
            'Walk-in stay extended successfully.'
        );
    }

    private function formatWalkinTransaction(TransactionWalkin $transaction): array
    {
        $timezone = 'Asia/Manila';
        $totalHours = (int) ($transaction->hours ?? 0) + (int) ($transaction->extend_hours ?? 0);

        return [
            'id' => $transaction->id,
            'unique_id' => $transaction->unique_id,
            'customer_name' => strtoupper(trim($transaction->customer?->name ?? '—')),
            'room_name' => $transaction->room?->name,
            'bed_name' => $transaction->bed?->name,
            'transaction_type' => (int) $transaction->transaction_type,
            'transaction_type_label' => self::TRANSACTION_TYPE_LABELS[(int) $transaction->transaction_type] ?? 'Unknown',
            'hours' => $totalHours > 0 ? $totalHours : (int) ($transaction->hours ?? 0),
            'rates' => (float) ($transaction->rates ?? 0),
            'login' => $transaction->login
                ? Carbon::parse($transaction->login)->timezone($timezone)->toDateTimeString()
                : null,
            'expected_out' => $transaction->time_out
                ? Carbon::parse($transaction->time_out)->timezone($timezone)->toDateTimeString()
                : null,
            'logout' => $transaction->logout
                ? Carbon::parse($transaction->logout)->timezone($timezone)->toDateTimeString()
                : null,
            'status' => (int) $transaction->status,
            'status_label' => (int) $transaction->status === 1 ? 'Login' : 'Logout',
        ];
    }
}
