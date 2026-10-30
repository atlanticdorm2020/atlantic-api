<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Bed;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;

class TransactionCheckoutService
{
    public function __construct(
        private readonly TransactionDayCalculator $dayCalculator,
    ) {
    }

    /**
     * @return array{transaction: Transaction, penalty: float, balance: int, consumed_days: int}
     */
    public function checkout(Transaction $transaction, int $locationId): array
    {
        if ($transaction->status !== '1') {
            throw new \InvalidArgumentException('Only checked-in transactions can be checked out.');
        }

        return DB::transaction(function () use ($transaction, $locationId) {
            $consumeDay = $this->dayCalculator->consumedDaysForCheckout($transaction);
            $totalApplied = (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0);
            $penalty = 0.0;
            $balance = 0;

            if ($consumeDay > $totalApplied) {
                $penalty = (float) ($transaction->rates ?? 0) * ($consumeDay - $totalApplied);
            } else {
                $balance = $totalApplied > $consumeDay ? $totalApplied - $consumeDay : 0;
            }

            $penaltyDay = $consumeDay > (int) ($transaction->no_day ?? 0)
                ? $consumeDay - $totalApplied
                : 0;

            $amount = round(((float) ($transaction->amount ?? 0)) + $penalty, 2);
            $finalAmount = round(((float) ($transaction->final_amount ?? 0)) + $penalty, 2);

            $transaction->update([
                'amount' => $amount,
                'consumed_day' => $consumeDay,
                'remaining_day' => $balance,
                'penalty_day' => max(0, $penaltyDay),
                'final_amount' => $finalAmount,
                'logout' => now('Asia/Manila'),
                'status' => '0',
            ]);

            if ($transaction->bed_id) {
                Bed::whereKey($transaction->bed_id)->update(['status' => 1]);
            }

            Customer::whereKey($transaction->customer_id)->update(['status' => 0]);

            TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 2,
                'amount' => 0,
            ]);

            $bed = $transaction->bed_id ? Bed::find($transaction->bed_id) : null;
            $this->updateCustomerBalance(
                $transaction->customer_id,
                $locationId,
                $balance,
                (int) ($bed?->room_id ?? 0),
            );

            if ($penalty > 0) {
                TransactionDetail::create([
                    'transaction_id' => $transaction->id,
                    'location_id' => $locationId,
                    'status' => 5,
                    'penalty_days' => $consumeDay - $totalApplied,
                    'amount' => $penalty,
                ]);
            }

            return [
                'transaction' => $transaction->fresh(),
                'penalty' => $penalty,
                'balance' => $balance,
                'consumed_days' => $consumeDay,
            ];
        });
    }

    private function updateCustomerBalance(int $customerId, int $locationId, int $balance, int $roomId): void
    {
        $updated = Balance::query()
            ->where('location_id', $locationId)
            ->where('customer_id', $customerId)
            ->update([
                'balance' => $balance,
                'room_id' => $roomId,
            ]);

        if ($updated === 0 && $roomId > 0) {
            Balance::create([
                'customer_id' => $customerId,
                'location_id' => $locationId,
                'room_id' => $roomId,
                'balance' => $balance,
            ]);
        }
    }
}
