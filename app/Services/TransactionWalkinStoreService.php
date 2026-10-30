<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\CustomerWalkin;
use App\Models\CustomRate;
use App\Models\Transaction;
use App\Models\TransactionWalkin;
use App\Models\TransactionWalkinDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionWalkinStoreService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $locationId, int $userId): TransactionWalkin
    {
        $customRate = CustomRate::with('rate')->findOrFail($data['custom_rate_id']);
        $bed = Bed::with('room')->findOrFail($data['bed_id']);
        $room = $bed->room;

        if (! $room || (int) $room->location_id !== $locationId) {
            throw new \InvalidArgumentException('Selected bed does not belong to your branch.');
        }

        if ((int) $room->is_bedspace !== 0) {
            throw new \InvalidArgumentException('Walk-in transactions are only allowed for non-bedspace rooms.');
        }

        if ((int) $customRate->location_id !== $locationId) {
            throw new \InvalidArgumentException('Selected rate does not belong to your branch.');
        }

        if ((int) $customRate->type !== (int) $data['transaction_type']) {
            throw new \InvalidArgumentException('Selected rate does not match the transaction type.');
        }

        if ((int) $customRate->status !== 1) {
            throw new \InvalidArgumentException('Selected rate is not active.');
        }

        $activeWalkin = TransactionWalkin::query()
            ->where('customer_id', $data['customer_id'])
            ->where('status', 1)
            ->exists();

        if ($activeWalkin) {
            throw new \InvalidArgumentException('This walk-in customer already has an active stay.');
        }

        $bedOccupied = TransactionWalkin::query()
            ->where('bed_id', $bed->id)
            ->where('status', 1)
            ->exists()
            || Transaction::query()
                ->where('bed_id', $bed->id)
                ->where('status', '1')
                ->exists();

        if ($bedOccupied) {
            throw new \InvalidArgumentException('Selected bed is already occupied.');
        }

        if ((int) $bed->status !== 1) {
            throw new \InvalidArgumentException('Selected bed is not available.');
        }

        $hours = (int) $customRate->hours;
        $amount = (float) ($customRate->rate?->amount ?? 0);
        $login = Carbon::parse($data['login'] ?? now('Asia/Manila'), 'Asia/Manila');
        $timeOut = $login->copy()->addHours($hours);

        return DB::transaction(function () use ($data, $locationId, $userId, $bed, $hours, $amount, $login, $timeOut) {
            $transaction = TransactionWalkin::create([
                'unique_id' => $this->generateUniqueId($locationId),
                'location_id' => $locationId,
                'customer_id' => $data['customer_id'],
                'room_id' => $bed->room_id,
                'bed_id' => $bed->id,
                'transaction_type' => (int) $data['transaction_type'],
                'hours' => $hours,
                'extend_hours' => 0,
                'rates' => $amount,
                'login' => $login,
                'time_out' => $timeOut,
                'status' => 1,
                'user_id' => $userId,
            ]);

            TransactionWalkinDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 1,
                'hours' => $hours,
                'amount' => $amount,
            ]);

            Bed::whereKey($bed->id)->update(['status' => 0]);
            CustomerWalkin::whereKey($data['customer_id'])->update(['status' => 1]);

            return $transaction->fresh(['customer', 'room', 'bed']);
        });
    }

    private function generateUniqueId(int $locationId): string
    {
        $next = TransactionWalkin::query()
            ->where('location_id', $locationId)
            ->count() + 1;

        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
