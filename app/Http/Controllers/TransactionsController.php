<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Bed;
use App\Models\Customer;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionWalkin;
use App\Services\TransactionCheckoutService;
use App\Services\TransactionPricing;
use App\Services\TransactionStoreService;
use App\Services\TransactionSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
class TransactionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;

        $query = Transaction::with(['bed.room', 'customer', 'location'])
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->when($request->filled('location_id'), fn ($builder) => $builder->where('location_id', $request->integer('location_id')))
            ->when($request->filled('customer_id'), fn ($builder) => $builder->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($inner) use ($search) {
                    $inner->where('unique_id', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('middle_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('bed', function ($bedQuery) use ($search) {
                            $bedQuery->where('name', 'like', "%{$search}%")
                                ->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('name', 'like', "%{$search}%"));
                        });
                });
            })
            ->latest();

        if ($request->boolean('all')) {
            return $this->success(
                $query->get()->map(fn (Transaction $transaction) => $this->formatTransaction($transaction))
            );
        }

        $paginator = $query->paginate($this->perPage($request));
        $paginator->getCollection()->transform(fn (Transaction $transaction) => $this->formatTransaction($transaction));

        return $this->paginatedResponse($paginator);
    }

    public function formOptions(Request $request): JsonResponse
    {
        $locationId = $request->user()?->location_id;
        $search = $request->filled('search') ? $request->string('search') : null;

        $customersQuery = Customer::query()
            ->when($search, function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('sirb_no', 'like', "%{$search}%")
                        ->orWhere('mobile_no', 'like', "%{$search}%");
                });
            }, fn ($builder) => $builder->where('status', 1))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $customers = $customersQuery
            ->limit($search ? 50 : 500)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'label' => strtoupper(trim(
                    ($customer->last_name ?? '') . ', ' . trim(($customer->first_name ?? '') . ' ' . ($customer->middle_name ?? ''))
                )),
            ])
            ->values();

        $rooms = Room::with('rate')
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('status', 1)
            ->orderBy('ordered')
            ->get()
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'rate' => $room->rate?->amount,
            ])
            ->values();

        return $this->success([
            'customers' => $customers,
            'rooms' => $rooms,
            'customers_total' => $search
                ? $customers->count()
                : Customer::where('status', 1)->count(),
        ]);
    }

    public function formPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        $locationId = $request->user()?->location_id;
        $customer = Customer::findOrFail($data['customer_id']);

        $previousTransaction = Transaction::query()
            ->with('bed')
            ->where('customer_id', $customer->id)
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->latest()
            ->first();

        $balanceRecord = Balance::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->latest('balance_id')
            ->first();

        $remainingBalance = (int) ($balanceRecord?->balance ?? $customer->balance ?? 0);
        $hasRemainingBalance = $remainingBalance > 0;

        $suggestedRoomId = null;
        $suggestedBedId = null;

        if ($hasRemainingBalance) {
            $suggestedRoomId = $balanceRecord?->room_id ?? $previousTransaction?->bed?->room_id;
            $suggestedBedId = $previousTransaction?->bed_id;
        }

        $effectiveRoomId = $data['room_id'] ?? $suggestedRoomId;

        $roomRate = null;
        $beds = collect();
        $upgradePenalty = 0.0;

        if ($effectiveRoomId) {
            $room = Room::with('rate')->find($effectiveRoomId);

            if ($room) {
                $roomRate = (float) ($room->rate?->amount ?? 0);

                $roomBedIds = Bed::query()->where('room_id', $room->id)->pluck('id');

                $occupiedBedIds = Transaction::query()
                    ->whereIn('status', ['1', 1])
                    ->whereIn('bed_id', $roomBedIds)
                    ->pluck('bed_id')
                    ->merge(
                        TransactionWalkin::query()
                            ->where('status', 1)
                            ->whereIn('bed_id', $roomBedIds)
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

                if ($hasRemainingBalance && $suggestedBedId) {
                    $suggestedBedInRoom = Bed::query()
                        ->whereKey($suggestedBedId)
                        ->where('room_id', $room->id)
                        ->exists();

                    if (! $suggestedBedInRoom || ! $beds->contains('id', (int) $suggestedBedId)) {
                        $suggestedBedId = null;
                    }
                }
            }
        }

        if ($hasRemainingBalance && $roomRate && $previousTransaction?->rates !== null) {
            $previousRate = (float) $previousTransaction->rates;
            if ($roomRate > $previousRate) {
                $upgradePenalty = round(
                    ($remainingBalance * $roomRate) - ($remainingBalance * $previousRate),
                    2
                );
            }
        }

        return $this->success([
            'room_rate' => $roomRate,
            'previous_room_rate' => (float) ($previousTransaction?->rates ?? 0),
            'no_day' => $hasRemainingBalance ? $remainingBalance : null,
            'remaining_balance' => $remainingBalance,
            'has_remaining_balance' => $hasRemainingBalance,
            'upgrade_penalty' => max(0, $upgradePenalty),
            'suggested_room_id' => $suggestedRoomId,
            'suggested_bed_id' => $suggestedBedId,
            'beds' => $beds,
        ]);
    }

    public function store(Request $request, TransactionStoreService $storeService): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $locationId = (int) ($data['location_id'] ?? $request->user()?->location_id ?? 0);

        if (! $locationId) {
            return $this->error('Branch location is required.', 422);
        }

        $data['location_id'] = $locationId;

        $existingActive = Transaction::query()
            ->where('customer_id', $data['customer_id'])
            ->where('status', '1')
            ->where('location_id', $locationId)
            ->exists();

        if ($existingActive) {
            return $this->error('This customer already has an active check-in.', 422);
        }

        $bed = Bed::with('room')->findOrFail($data['bed_id']);

        if ($bed->room?->location_id !== $locationId) {
            return $this->error('Selected bed does not belong to your branch.', 422);
        }

        $bedOccupied = Transaction::query()
            ->where('bed_id', $bed->id)
            ->where('location_id', $locationId)
            ->whereIn('status', ['1', 1])
            ->exists()
            || TransactionWalkin::query()
                ->where('bed_id', $bed->id)
                ->where('location_id', $locationId)
                ->where('status', 1)
                ->exists();

        if ($bedOccupied || (int) $bed->status !== 1) {
            return $this->error(
                'This bed already has an active stay. Choose a vacant bed or check out the current guest first.',
                422
            );
        }

        if (! isset($data['rates'])) {
            $bed->loadMissing('room.rate');
            $data['rates'] = (float) ($bed->room?->rate?->amount ?? 0);
        }

        $data['login'] = $data['login'] ?? now('Asia/Manila');

        try {
            $result = $storeService->create($data, $locationId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $message = $result['upgrade_penalty'] > 0
            ? 'Transaction created with room upgrade penalty.'
            : 'Transaction created.';

        return $this->success(
            $this->formatTransaction($result['transaction']->load(['bed.room', 'customer', 'location'])),
            $message,
            201
        );
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return $this->success($this->formatTransaction($transaction->load([
            'bed.room.location',
            'customer',
            'location',
            'details',
            'histories',
            'penalties',
            'switchHistories',
            'bedHistories',
        ])));
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $this->validatedPayload($request, true);

        if (array_key_exists('extend_day', $data) || array_key_exists('no_day', $data) || array_key_exists('rates', $data) || array_key_exists('penalty', $data)) {
            $merged = array_merge($transaction->only(['no_day', 'extend_day', 'rates', 'penalty']), $data);
            $data['final_amount'] = $this->calculateFinalAmount($merged);
        }

        $transaction->update($data);

        return $this->success(
            $this->formatTransaction($transaction->fresh()->load(['bed.room', 'customer', 'location'])),
            'Transaction updated.'
        );
    }

    public function checkout(Request $request, Transaction $transaction, TransactionCheckoutService $checkoutService): JsonResponse
    {
        $locationId = (int) ($request->user()?->location_id ?? $transaction->location_id ?? 0);

        if (! $locationId) {
            return $this->error('Branch location is required.', 422);
        }

        try {
            $result = $checkoutService->checkout($transaction, $locationId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $message = $result['penalty'] > 0
            ? 'Guest checked out with overdue penalty.'
            : 'Guest checked out successfully.';

        return $this->success(
            $this->formatTransaction($result['transaction']->load(['bed.room', 'customer', 'location'])),
            $message
        );
    }

    public function extend(Request $request, Transaction $transaction, TransactionPricing $pricing): JsonResponse
    {
        if ($transaction->status !== '1') {
            return $this->error('Only checked-in transactions can be extended.', 422);
        }

        $data = $request->validate([
            'extend_day' => ['required', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'isContinue' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($transaction, $data, $pricing) {
            $addedDays = (int) $data['extend_day'];
            $previousExtendDay = (int) ($transaction->extend_day ?? 0);
            $newExtendDay = $previousExtendDay + $addedDays;
            $rate = (float) ($transaction->rates ?? 0);
            $addedAmount = $pricing->extensionAmount($addedDays, $rate);
            $amount = array_key_exists('amount', $data)
                ? (float) $data['amount']
                : round(((float) ($transaction->amount ?? 0)) + $addedAmount, 2);
            $finalAmount = round(((float) ($transaction->final_amount ?? 0)) + $addedAmount, 2);
            $penalty = array_key_exists('penalty', $data)
                ? (float) $data['penalty']
                : (float) ($transaction->penalty ?? 0);

            $transaction->update([
                'extend_day' => $newExtendDay,
                'amount' => $amount,
                'final_amount' => $finalAmount,
                'penalty' => $penalty,
                'isContinue' => $data['isContinue'] ?? $transaction->isContinue,
            ]);

            TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $transaction->location_id,
                'status' => 3,
                'extend_days' => $addedDays,
                'penalty_days' => 0,
                'extend_date' => now('Asia/Manila'),
                'amount' => $addedAmount,
            ]);
        });

        return $this->success(
            $this->formatTransaction($transaction->fresh()->load(['bed.room', 'customer', 'location', 'details'])),
            'Stay extended successfully.'
        );
    }

    public function switchOptions(Request $request, Transaction $transaction, TransactionSwitchService $switchService): JsonResponse
    {
        $locationId = (int) ($request->user()?->location_id ?? $transaction->location_id ?? 0);

        if ($transaction->status !== '1') {
            return $this->error('Only checked-in transactions can be switched.', 422);
        }

        if ($locationId && (int) $transaction->location_id !== $locationId) {
            return $this->error('Transaction does not belong to your branch.', 422);
        }

        $bedId = $request->filled('bed_id') ? $request->integer('bed_id') : null;

        return $this->success($switchService->options($transaction, $locationId, $bedId));
    }

    public function switch(Request $request, Transaction $transaction, TransactionSwitchService $switchService): JsonResponse
    {
        $locationId = (int) ($request->user()?->location_id ?? $transaction->location_id ?? 0);

        if (! $locationId) {
            return $this->error('Branch location is required.', 422);
        }

        $data = $request->validate([
            'bed_id' => ['required', 'integer', 'exists:beds,id'],
        ]);

        try {
            $result = $switchService->switch($transaction, (int) $data['bed_id'], $locationId);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $message = $result['switch_amount'] > 0 || $result['upgrade_penalty'] > 0
            ? 'Guest switched with rate adjustment.'
            : 'Guest switched successfully.';

        return $this->success(
            $this->formatTransaction($result['transaction']->load(['bed.room', 'customer', 'location'])),
            $message
        );
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return $this->success(null, 'Transaction deleted.');
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'unique_id' => ['nullable', 'string', 'max:100'],
            'bed_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:beds,id'],
            'customer_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:customers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'no_day' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0'],
            'extend_day' => ['nullable', 'integer', 'min:0'],
            'remaining_day' => ['nullable', 'integer', 'min:0'],
            'consumed_day' => ['nullable', 'integer', 'min:0'],
            'penalty_day' => ['nullable', 'integer', 'min:0'],
            'rates' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:191'],
            'isContinue' => ['nullable', 'integer'],
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'final_amount' => ['nullable', 'numeric', 'min:0'],
            'login' => [$partial ? 'sometimes' : 'required', 'date'],
            'logout' => ['nullable', 'date'],
        ];

        return $request->validate($rules);
    }

    private function formatTransaction(Transaction $transaction): array
    {
        $timezone = 'Asia/Manila';
        $login = $transaction->login ? Carbon::parse($transaction->login)->timezone($timezone) : null;
        $logout = $transaction->logout ? Carbon::parse($transaction->logout)->timezone($timezone) : null;
        $totalDays = (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0);
        $expectedOut = $login && $transaction->status === '1'
            ? $login->copy()->addDays($totalDays)->setTime(11, 59, 0)
            : null;
        $isOverdue = $transaction->status === '1' && $expectedOut && $expectedOut->isPast();

        $customer = $transaction->customer;
        $customerName = $customer
            ? strtoupper(trim(($customer->last_name ?? '') . ', ' . trim(($customer->first_name ?? '') . ' ' . ($customer->middle_name ?? ''))))
            : null;

        return [
            'id' => $transaction->id,
            'unique_id' => $transaction->unique_id,
            'bed_id' => $transaction->bed_id,
            'customer_id' => $transaction->customer_id,
            'location_id' => $transaction->location_id,
            'no_day' => $transaction->no_day,
            'extend_day' => $transaction->extend_day,
            'remaining_day' => $transaction->remaining_day,
            'consumed_day' => $transaction->consumed_day,
            'penalty_day' => $transaction->penalty_day,
            'rates' => $transaction->rates,
            'amount' => $transaction->amount,
            'status' => $transaction->status,
            'status_label' => $this->statusLabel($transaction->status),
            'isContinue' => $transaction->isContinue,
            'is_continue_label' => ((int) ($transaction->isContinue ?? 0)) === 1 ? 'Yes' : 'No',
            'penalty' => $transaction->penalty,
            'final_amount' => $transaction->final_amount,
            'login' => $transaction->login,
            'logout' => $transaction->logout,
            'expected_out' => $expectedOut?->toIso8601String(),
            'is_overdue' => $isOverdue,
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
            'customer_name' => $customerName,
            'room_name' => $transaction->bed?->room?->name,
            'bed_name' => $transaction->bed?->name,
            'location_name' => $transaction->location?->name,
            'customer' => $customer,
            'bed' => $transaction->bed,
            'location' => $transaction->location,
        ];
    }

    private function statusLabel(?string $status): string
    {
        return match ((string) $status) {
            '1' => 'in',
            '0' => 'out',
            '2' => 'pending',
            default => (string) $status,
        };
    }

    private function calculateFinalAmount(array $data): float
    {
        $days = (int) ($data['no_day'] ?? 0) + (int) ($data['extend_day'] ?? 0);
        $rates = (float) ($data['rates'] ?? 0);
        $penalty = (float) ($data['penalty'] ?? 0);

        return round(($days * $rates) + $penalty, 2);
    }

    private function generateUniqueId(): string
    {
        $latest = Transaction::query()->orderByDesc('id')->value('unique_id');
        $next = is_numeric($latest) ? ((int) $latest + 1) : Transaction::max('id') + 1;

        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
