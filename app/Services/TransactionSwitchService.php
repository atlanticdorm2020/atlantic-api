<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\BedHistory;
use App\Models\SwitchHistory;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;

class TransactionSwitchService
{
    public function __construct(
        private readonly TransactionDayCalculator $dayCalculator,
    ) {
    }

    /**
     * @return array{
     *     rooms: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     current_bed_id: int|null,
     *     days_remaining: int,
     *     current_rate: float,
     *     preview?: array<string, int|float>
     * }
     */
    public function options(Transaction $transaction, int $locationId, ?int $bedId = null): array
    {
        $occupiedBedIds = Transaction::query()
            ->where('status', '1')
            ->whereKeyNot($transaction->id)
            ->pluck('bed_id');

        $rooms = Bed::query()
            ->with('room.rate')
            ->whereHas('room', fn ($builder) => $builder
                ->where('location_id', $locationId)
                ->where('status', 1))
            ->where('status', 1)
            ->whereNotIn('id', $occupiedBedIds)
            ->when($transaction->bed_id, fn ($builder) => $builder->where('id', '!=', $transaction->bed_id))
            ->orderBy('sort')
            ->get()
            ->groupBy(fn (Bed $bed) => $bed->room_id)
            ->map(function ($beds, $roomId) {
                $room = $beds->first()->room;

                return [
                    'id' => (int) $roomId,
                    'name' => $room?->name,
                    'rate' => (float) ($room?->rate?->amount ?? 0),
                    'beds' => $beds->map(fn (Bed $bed) => [
                        'id' => $bed->id,
                        'name' => $bed->name,
                    ])->values(),
                ];
            })
            ->values();

        $result = [
            'rooms' => $rooms,
            'current_bed_id' => $transaction->bed_id,
            'days_remaining' => $this->remainingDays($transaction),
            'current_rate' => (float) ($transaction->rates ?? 0),
        ];

        if ($bedId) {
            $preview = $this->previewSwitch($transaction, $bedId);
            if ($preview) {
                $result['preview'] = $preview;
            }
        }

        return $result;
    }

    /**
     * @return array{transaction: Transaction, switch_amount: float, upgrade_penalty: float}|null
     */
    public function previewSwitch(Transaction $transaction, int $bedId): ?array
    {
        $newBed = Bed::with('room.rate')->find($bedId);
        if (! $newBed) {
            return null;
        }

        $previousRate = (float) ($transaction->rates ?? 0);
        $currentRate = (float) ($newBed->room?->rate?->amount ?? 0);
        $charges = $this->calculateRateUpgradeCharges($transaction, $previousRate, $currentRate);

        return [
            'consumed_days' => $charges['consumed_days'],
            'total_applied' => $charges['total_applied'],
            'balance_days' => $charges['balance'],
            'switch_amount' => $charges['new_amount'],
            'upgrade_penalty' => $charges['penalty'],
            'overdue_penalty_amount' => $charges['overdue_penalty_amount'],
            'new_rate' => $currentRate,
        ];
    }

    /**
     * @return array{transaction: Transaction, switch_amount: float, upgrade_penalty: float}
     */
    public function switch(Transaction $transaction, int $bedId, int $locationId): array
    {
        if ($transaction->status !== '1') {
            throw new \InvalidArgumentException('Only checked-in transactions can be switched.');
        }

        if ((int) $transaction->bed_id === $bedId) {
            throw new \InvalidArgumentException('Guest is already assigned to this bed.');
        }

        $newBed = Bed::with('room.rate')->findOrFail($bedId);

        if ((int) ($newBed->room?->location_id ?? 0) !== $locationId) {
            throw new \InvalidArgumentException('Selected bed does not belong to your branch.');
        }

        $isOccupied = Transaction::query()
            ->where('status', '1')
            ->where('bed_id', $bedId)
            ->whereKeyNot($transaction->id)
            ->exists();

        if ($isOccupied) {
            throw new \InvalidArgumentException('Selected bed is no longer available.');
        }

        $oldBedId = (int) $transaction->bed_id;
        $previousRate = (float) ($transaction->rates ?? 0);
        $currentRate = (float) ($newBed->room?->rate?->amount ?? 0);
        $charges = $this->calculateRateUpgradeCharges($transaction, $previousRate, $currentRate);

        return DB::transaction(function () use ($transaction, $newBed, $oldBedId, $previousRate, $currentRate, $charges, $locationId, $bedId) {
            if ($oldBedId) {
                Bed::whereKey($oldBedId)->update(['status' => 1]);
            }

            Bed::whereKey($newBed->id)->update(['status' => 0]);

            if ($currentRate > $previousRate) {
                if ($charges['balance'] > 0) {
                    SwitchHistory::create([
                        'transaction_id' => $transaction->id,
                        'day_remain' => $charges['balance'],
                        'rate' => $currentRate,
                    ]);

                    $transaction->rates = $currentRate;
                }

                if ($charges['overdue_penalty_amount'] > 0) {
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'location_id' => $locationId,
                        'status' => 5,
                        'penalty_days' => 1,
                        'amount' => round($currentRate - $previousRate, 2),
                    ]);
                }
            }

            $transaction->bed_id = $bedId;
            $transaction->rates = $currentRate;
            $transaction->save();

            $switchDetail = TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'location_id' => $locationId,
                'status' => 4,
                'penalty_days' => $charges['new_balance'],
                'amount' => round($charges['new_amount'], 2),
            ]);

            BedHistory::create([
                'transaction_id' => $transaction->id,
                'transaction_detail_id' => $switchDetail->id,
                'customer_id' => $transaction->customer_id,
                'bed_id' => $bedId,
            ]);

            return [
                'transaction' => $transaction->fresh(),
                'switch_amount' => round($charges['new_amount'], 2),
                'upgrade_penalty' => round($charges['penalty'], 2),
            ];
        });
    }

    public function remainingDays(Transaction $transaction): int
    {
        $totalApplied = (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0);

        return max(0, $totalApplied - $this->dayCalculator->consumedDaysForSwitch($transaction));
    }

    public function calculateConsumedDays(Transaction $transaction): int
    {
        return $this->dayCalculator->consumedDaysForSwitch($transaction);
    }

    /**
     * @return array{
     *     consumed_days: int,
     *     total_applied: int,
     *     balance: int,
     *     penalty: float,
     *     overdue_penalty_amount: float,
     *     new_amount: float,
     *     new_balance: int
     * }
     */
    private function calculateRateUpgradeCharges(
        Transaction $transaction,
        float $previousRate,
        float $currentRate,
    ): array {
        $totalApplied = (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0);
        $consumedDays = $this->dayCalculator->consumedDaysForSwitch($transaction);
        $balance = 0;
        $penalty = 0.0;
        $overduePenaltyAmount = 0.0;
        $newAmount = 0.0;
        $newBalance = 0;

        if ($currentRate <= $previousRate) {
            return [
                'consumed_days' => $consumedDays,
                'total_applied' => $totalApplied,
                'balance' => 0,
                'penalty' => 0.0,
                'overdue_penalty_amount' => 0.0,
                'new_amount' => 0.0,
                'new_balance' => 0,
            ];
        }

        if ($consumedDays > $totalApplied) {
            $penalty = (float) $transaction->rates * ($consumedDays - $totalApplied);
            $overduePenaltyAmount = $penalty;
        } else {
            $balance = $totalApplied > $consumedDays ? $totalApplied - $consumedDays : 0;
        }

        if ($balance > 0) {
            $latestSwitch = SwitchHistory::query()
                ->where('transaction_id', $transaction->id)
                ->latest('id')
                ->first();

            if ($latestSwitch) {
                $newBalance = (int) $latestSwitch->day_remain - $balance;

                if ($newBalance === 0) {
                    $previousAmount = $balance * (float) $latestSwitch->rate;
                    $currentAmount = $balance * $currentRate;
                    $newAmount = $currentAmount - $previousAmount;
                    $newBalance = $balance;
                } else {
                    $previousAmount = $newBalance * (float) $latestSwitch->rate;
                    $currentAmount = $newBalance * $currentRate;
                    $newAmount = $currentAmount - $previousAmount;
                }
            } else {
                $previousAmount = $balance * $previousRate;
                $currentAmount = $balance * $currentRate;
                $newAmount = $currentAmount - $previousAmount;
                $newBalance = $balance;
            }
        }

        if ($penalty > 0) {
            $overduePenaltyAmount = $penalty;
        }

        return [
            'consumed_days' => $consumedDays,
            'total_applied' => $totalApplied,
            'balance' => $balance,
            'penalty' => $penalty,
            'overdue_penalty_amount' => $overduePenaltyAmount,
            'new_amount' => round($newAmount, 2),
            'new_balance' => $newBalance,
        ];
    }
}
