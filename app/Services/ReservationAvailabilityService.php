<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\TransactionWalkin;
use Carbon\Carbon;

class ReservationAvailabilityService
{
    /**
     * @return array<int, array{id: int, name: string, rate: float, available_beds: int}>
     */
    public function availableBedspaceRooms(int $locationId, Carbon $checkIn, int $stayDays = 1): array
    {
        $checkOut = $checkIn->copy()->addDays(max(1, $stayDays));

        return Room::query()
            ->with(['rate', 'beds'])
            ->where('location_id', $locationId)
            ->where('status', 1)
            ->where('is_bedspace', 1)
            ->orderBy('ordered')
            ->get()
            ->map(function (Room $room) use ($locationId, $checkIn, $checkOut) {
                $availableBeds = $this->countAvailableBeds($room, $locationId, $checkIn, $checkOut);

                if ($availableBeds < 1) {
                    return null;
                }

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'rate' => (float) ($room->rate?->amount ?? 0),
                    'available_beds' => $availableBeds,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, available_beds: int}>
     */
    public function availableWalkinRooms(int $locationId, Carbon $checkIn, int $hours = 3): array
    {
        $checkOut = $checkIn->copy()->addHours(max(1, $hours));

        return Room::query()
            ->with('beds')
            ->where('location_id', $locationId)
            ->where('status', 1)
            ->where('is_bedspace', 0)
            ->orderBy('ordered')
            ->get()
            ->map(function (Room $room) use ($locationId, $checkIn, $checkOut) {
                $availableBeds = $this->countAvailableBeds($room, $locationId, $checkIn, $checkOut);

                if ($availableBeds < 1) {
                    return null;
                }

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'available_beds' => $availableBeds,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function countAvailableBeds(Room $room, int $locationId, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): int
    {
        return $room->beds
            ->filter(fn (Bed $bed) => $this->isBedAvailable($bed, $locationId, $checkIn, $checkOut, $excludeReservationId))
            ->count();
    }

    private function isBedAvailable(Bed $bed, int $locationId, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): bool
    {
        if ($this->bedBlockedByTransaction((int) $bed->id, $locationId, $checkIn, $checkOut)) {
            return false;
        }

        if ($this->bedBlockedByWalkin((int) $bed->id, $locationId, $checkIn, $checkOut)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function availableBedsForReservation(Reservation $reservation): array
    {
        if (! $reservation->room_id) {
            return [];
        }

        $room = Room::with('beds')->find($reservation->room_id);

        if (! $room) {
            return [];
        }

        [$checkIn, $checkOut] = $this->reservationWindow($reservation);
        $locationId = (int) $reservation->location_id;

        return $room->beds
            ->filter(fn (Bed $bed) => $this->bedIsAvailable($bed, $locationId, $checkIn, $checkOut, $reservation->id))
            ->filter(fn (Bed $bed) => $this->bedIsReadyForImmediateCheckIn($bed))
            ->map(fn (Bed $bed) => [
                'id' => $bed->id,
                'name' => $bed->name,
            ])
            ->values()
            ->all();
    }

    public function countAvailableBedsForReservation(Reservation $reservation): int
    {
        return count($this->availableBedsForReservation($reservation));
    }

    private function bedIsReadyForImmediateCheckIn(Bed $bed): bool
    {
        if ((int) $bed->status !== 1) {
            return false;
        }

        $hasActiveTransaction = Transaction::query()
            ->where('bed_id', $bed->id)
            ->where('status', '1')
            ->exists();

        if ($hasActiveTransaction) {
            return false;
        }

        return ! TransactionWalkin::query()
            ->where('bed_id', $bed->id)
            ->where('status', 1)
            ->exists();
    }

    private function bedBlockedByTransaction(int $bedId, int $locationId, Carbon $checkIn, Carbon $checkOut): bool
    {
        $transactions = Transaction::query()
            ->where('bed_id', $bedId)
            ->where('location_id', $locationId)
            ->where('status', '1')
            ->get();

        foreach ($transactions as $transaction) {
            if (! $transaction->login) {
                continue;
            }

            $occupiedStart = $transaction->login->copy()->timezone('Asia/Manila');
            $occupiedEnd = $occupiedStart->copy()->startOfDay()->addDays(
                (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0)
            );

            if ($this->periodsOverlap($checkIn, $checkOut, $occupiedStart, $occupiedEnd)) {
                return true;
            }
        }

        return false;
    }

    private function bedBlockedByWalkin(int $bedId, int $locationId, Carbon $checkIn, Carbon $checkOut): bool
    {
        $walkins = TransactionWalkin::query()
            ->where('bed_id', $bedId)
            ->where('location_id', $locationId)
            ->where('status', 1)
            ->get();

        foreach ($walkins as $walkin) {
            $occupiedStart = ($walkin->login ?? now())->copy()->timezone('Asia/Manila');
            $occupiedEnd = ($walkin->time_out ?? $occupiedStart->copy()->addHours(
                (int) ($walkin->hours ?? 0) + (int) ($walkin->extend_hours ?? 0)
            ))->copy()->timezone('Asia/Manila');

            if ($this->periodsOverlap($checkIn, $checkOut, $occupiedStart, $occupiedEnd)) {
                return true;
            }
        }

        return false;
    }

    private function periodsOverlap(Carbon $startA, Carbon $endA, Carbon $startB, Carbon $endB): bool
    {
        return $startA->lt($endB) && $endA->gt($startB);
    }

    public function findAvailableBed(Room $room, int $locationId, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): ?Bed
    {
        $room->loadMissing('beds');

        foreach ($room->beds as $bed) {
            if ($this->bedIsAvailable($bed, $locationId, $checkIn, $checkOut, $excludeReservationId)) {
                return $bed;
            }
        }

        return null;
    }

    public function bedIsAvailable(Bed $bed, int $locationId, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): bool
    {
        return $this->isBedAvailable($bed, $locationId, $checkIn, $checkOut, $excludeReservationId);
    }

    public function reservationWindow(Reservation $reservation): array
    {
        $checkIn = $reservation->preferred_check_in->copy()->timezone('Asia/Manila');

        if ($reservation->type === Reservation::TYPE_WALKIN) {
            $hours = max(1, (int) ($reservation->hours ?? 1));

            return [$checkIn, $checkIn->copy()->addHours($hours)];
        }

        $days = max(1, (int) ($reservation->no_day ?? 1));

        return [$checkIn, $checkIn->copy()->addDays($days)];
    }
}
