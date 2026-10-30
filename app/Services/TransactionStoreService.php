<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Bed;
use App\Models\BedHistory;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionWalkin;
use Illuminate\Support\Facades\DB;

class TransactionStoreService
{
    public function __construct(
        private readonly TransactionPricing $pricing,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{transaction: Transaction, upgrade_penalty: float}
     */
    public function create(array $data, int $locationId): array
    {
        $bed = Bed::with('room.rate')->findOrFail($data['bed_id']);
        $customer = Customer::findOrFail($data['customer_id']);
        $currentRate = (float) ($data['rates'] ?? $bed->room?->rate?->amount ?? 0);
        $balanceRecord = $this->resolveBalanceRecord($customer->id, $locationId, (int) $bed->room_id);
        $prepaidBalance = (int) ($balanceRecord->balance ?? 0);

        $previousTransaction = Transaction::query()
            ->where('customer_id', $customer->id)
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->latest()
            ->first();

        $pricing = $this->pricing->resolve(
            requestedDays: (int) ($data['no_day'] ?? 0),
            currentRate: $currentRate,
            prepaidBalance: $prepaidBalance,
            previousRate: $previousTransaction?->rates !== null ? (float) $previousTransaction->rates : null,
        );

        if ($prepaidBalance === 0 && $pricing['no_day'] < $this->pricing->minDaysWithoutBalance()) {
            throw new \InvalidArgumentException('Transaction day must be greater than or equal to 5.');
        }

        return DB::transaction(function () use ($data, $locationId, $bed, $customer, $pricing, $prepaidBalance) {
            $bed = Bed::query()->whereKey($bed->id)->lockForUpdate()->firstOrFail();

            if ($this->bedHasActiveStay((int) $bed->id, $locationId)) {
                throw new \InvalidArgumentException(
                    'This bed already has an active stay. Choose a vacant bed or check out the current guest first.'
                );
            }
            $transaction = Transaction::create([
                'bed_id' => $bed->id,
                'unique_id' => $data['unique_id'] ?? $this->generateUniqueId($locationId),
                'customer_id' => $customer->id,
                'location_id' => $locationId,
                'no_day' => $pricing['no_day'],
                'rates' => $pricing['rate'],
                'amount' => $pricing['amount'],
                'final_amount' => $pricing['amount'],
                'status' => '1',
                'login' => $data['login'] ?? now(),
                'isContinue' => $pricing['is_continue'],
                'extend_day' => 0,
                'penalty_day' => 0,
                'penalty' => 0,
            ]);

            TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 1,
                'amount' => $pricing['amount'],
                'extend_days' => $pricing['no_day'],
            ]);

            if ($pricing['upgrade_penalty'] > 0) {
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'location_id' => $locationId,
                    'status' => 5,
                    'penalty_days' => $prepaidBalance,
                    'amount' => $pricing['upgrade_penalty'],
                ]);
            }

            BedHistory::create([
                'transaction_id' => $transaction->id,
                'customer_id' => $customer->id,
                'bed_id' => $bed->id,
            ]);

            Bed::whereKey($bed->id)->update(['status' => 0]);

            $customer->update([
                'location_id' => $locationId,
                'status' => 1,
            ]);

            return [
                'transaction' => $transaction,
                'upgrade_penalty' => $pricing['upgrade_penalty'],
            ];
        });
    }

    private function bedHasActiveStay(int $bedId, int $locationId): bool
    {
        $activeBedspace = Transaction::query()
            ->where('bed_id', $bedId)
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->whereIn('status', ['1', 1])
            ->exists();

        if ($activeBedspace) {
            return true;
        }

        return TransactionWalkin::query()
            ->where('bed_id', $bedId)
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->where('status', 1)
            ->exists();
    }

    private function resolveBalanceRecord(int $customerId, int $locationId, int $roomId): Balance
    {
        $balance = Balance::query()
            ->where('customer_id', $customerId)
            ->where('location_id', $locationId)
            ->first();

        if ($balance) {
            return $balance;
        }

        return Balance::create([
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'room_id' => $roomId,
            'balance' => 0,
        ]);
    }

    private function generateUniqueId(int $locationId): string
    {
        $next = Transaction::query()
            ->where('location_id', $locationId)
            ->count() + 1;

        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
