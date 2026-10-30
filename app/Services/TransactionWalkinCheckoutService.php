<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\CustomerWalkin;
use App\Models\TransactionWalkin;
use App\Models\TransactionWalkinDetail;
use Illuminate\Support\Facades\DB;

class TransactionWalkinCheckoutService
{
    public function checkout(TransactionWalkin $transaction, int $locationId): TransactionWalkin
    {
        if ((int) $transaction->status !== 1) {
            throw new \InvalidArgumentException('Only active walk-in transactions can be checked out.');
        }

        if ((int) $transaction->location_id !== $locationId) {
            throw new \InvalidArgumentException('This walk-in transaction does not belong to your branch.');
        }

        return DB::transaction(function () use ($transaction, $locationId) {
            $now = now('Asia/Manila');

            $transaction->update([
                'logout' => $now,
                'status' => 0,
            ]);

            TransactionWalkinDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 2,
                'hours' => 0,
                'amount' => 0,
            ]);

            if ($transaction->bed_id) {
                Bed::whereKey($transaction->bed_id)->update(['status' => 1]);
            }

            CustomerWalkin::whereKey($transaction->customer_id)->update(['status' => 0]);

            return $transaction->fresh(['customer', 'room', 'bed']);
        });
    }
}
