<?php

namespace App\Services;

use App\Models\CustomRate;
use App\Models\TransactionWalkin;
use App\Models\TransactionWalkinDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionWalkinExtendService
{
    public function extend(TransactionWalkin $transaction, int $customRateId, int $locationId): TransactionWalkin
    {
        if ((int) $transaction->status !== 1) {
            throw new \InvalidArgumentException('Only active walk-in transactions can be extended.');
        }

        if ((int) $transaction->location_id !== $locationId) {
            throw new \InvalidArgumentException('This walk-in transaction does not belong to your branch.');
        }

        $customRate = CustomRate::with('rate')->findOrFail($customRateId);

        if ((int) $customRate->location_id !== $locationId) {
            throw new \InvalidArgumentException('Selected rate does not belong to your branch.');
        }

        if ((int) $customRate->type !== (int) $transaction->transaction_type) {
            throw new \InvalidArgumentException('Selected rate does not match the transaction type.');
        }

        if ((int) $customRate->status !== 1) {
            throw new \InvalidArgumentException('Selected rate is not active.');
        }

        $addedHours = (int) $customRate->hours;
        $addedAmount = (float) ($customRate->rate?->amount ?? 0);

        return DB::transaction(function () use ($transaction, $locationId, $addedHours, $addedAmount) {
            $currentExtendHours = (int) ($transaction->extend_hours ?? 0);
            $timeOut = $transaction->time_out
                ? Carbon::parse($transaction->time_out, 'Asia/Manila')
                : now('Asia/Manila');

            $transaction->update([
                'extend_hours' => $currentExtendHours + $addedHours,
                'time_out' => $timeOut->copy()->addHours($addedHours),
            ]);

            TransactionWalkinDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 3,
                'hours' => $addedHours,
                'amount' => $addedAmount,
            ]);

            return $transaction->fresh(['customer', 'room', 'bed']);
        });
    }
}
