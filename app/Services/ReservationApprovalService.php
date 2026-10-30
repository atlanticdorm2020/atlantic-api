<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Customer;
use App\Models\CustomerWalkin;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\TransactionWalkin;
use Illuminate\Support\Facades\DB;

class ReservationApprovalService
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly TransactionStoreService $transactionStore,
        private readonly TransactionWalkinStoreService $walkinStore,
    ) {}

    /**
     * @return array{reservation: Reservation, transaction: Transaction|TransactionWalkin}
     */
    public function approve(Reservation $reservation, int $adminUserId, ?int $bedId = null): array
    {
        return DB::transaction(function () use ($reservation, $adminUserId, $bedId) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ((int) $reservation->status !== Reservation::STATUS_PENDING) {
                throw new \InvalidArgumentException('Only pending reservations can be approved.');
            }

            $transaction = $reservation->type === Reservation::TYPE_WALKIN
                ? $this->approveWalkin($reservation, $adminUserId, $bedId)
                : $this->approveBedspace($reservation, $bedId);

            $reservation->update(['status' => Reservation::STATUS_APPROVED]);

            return [
                'reservation' => $reservation->fresh(['location', 'room', 'customRate.rate', 'customer.user']),
                'transaction' => $transaction,
            ];
        });
    }

    public function reject(Reservation $reservation): Reservation
    {
        if ((int) $reservation->status !== Reservation::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only pending reservations can be rejected.');
        }

        $reservation->update(['status' => Reservation::STATUS_REJECTED]);

        return $reservation->fresh(['location', 'room', 'customRate.rate', 'customer.user']);
    }

    private function approveBedspace(Reservation $reservation, ?int $bedId): Transaction
    {
        if (! $reservation->room_id) {
            throw new \InvalidArgumentException('Bedspace reservation is missing a preferred room.');
        }

        $customer = Customer::findOrFail($reservation->customer_id);
        $locationId = (int) $reservation->location_id;
        $room = Room::with(['beds', 'rate'])->findOrFail($reservation->room_id);

        if ((int) $room->location_id !== $locationId || (int) $room->is_bedspace !== 1) {
            throw new \InvalidArgumentException('Reserved room is not a valid bedspace room for this branch.');
        }

        $bed = $this->resolveBed($reservation, $room, $locationId, $bedId);

        $existingActive = Transaction::query()
            ->where('customer_id', $customer->id)
            ->where('status', '1')
            ->where('location_id', $locationId)
            ->exists();

        if ($existingActive) {
            throw new \InvalidArgumentException('This customer already has an active bedspace check-in.');
        }

        $result = $this->transactionStore->create([
            'customer_id' => $customer->id,
            'bed_id' => $bed->id,
            'no_day' => (int) ($reservation->no_day ?? 1),
            'login' => $this->availability->reservationWindow($reservation)[0],
            'rates' => (float) ($room->rate?->amount ?? 0),
        ], $locationId);

        return $result['transaction'];
    }

    private function approveWalkin(Reservation $reservation, int $adminUserId, ?int $bedId): TransactionWalkin
    {
        if (! $reservation->room_id) {
            throw new \InvalidArgumentException('Walk-in reservation is missing a room.');
        }

        if (! $reservation->custom_rate_id) {
            throw new \InvalidArgumentException('Walk-in reservation is missing a rate package.');
        }

        if ($reservation->transaction_type === null) {
            throw new \InvalidArgumentException('Walk-in reservation is missing a transaction type.');
        }

        $customer = Customer::findOrFail($reservation->customer_id);
        $locationId = (int) $reservation->location_id;
        $room = Room::with('beds')->findOrFail($reservation->room_id);

        if ((int) $room->location_id !== $locationId || (int) $room->is_bedspace !== 0) {
            throw new \InvalidArgumentException('Reserved room is not a valid walk-in room for this branch.');
        }

        $bed = $this->resolveBed($reservation, $room, $locationId, $bedId);
        $walkinCustomer = $this->resolveWalkinCustomer($customer);

        return $this->walkinStore->create([
            'customer_id' => $walkinCustomer->id,
            'bed_id' => $bed->id,
            'custom_rate_id' => $reservation->custom_rate_id,
            'transaction_type' => (int) $reservation->transaction_type,
            'login' => $this->availability->reservationWindow($reservation)[0],
        ], $locationId, $adminUserId);
    }

    private function resolveBed(Reservation $reservation, Room $room, int $locationId, ?int $bedId): Bed
    {
        [$checkIn, $checkOut] = $this->availability->reservationWindow($reservation);
        $availableBeds = $this->availability->availableBedsForReservation($reservation);

        if ($availableBeds === []) {
            $roomLabel = $room->name ?: 'the selected room';

            throw new \InvalidArgumentException(
                "No available bed in {$roomLabel} for the requested check-in dates. "
                . 'Please verify the room is vacant or reject this reservation.'
            );
        }

        if ($bedId) {
            $bed = Bed::with('room')->findOrFail($bedId);

            if ((int) $bed->room_id !== (int) $room->id) {
                throw new \InvalidArgumentException('Selected bed does not belong to the reserved room.');
            }

            $isListed = collect($availableBeds)->contains(fn (array $item) => (int) $item['id'] === (int) $bed->id);

            if (! $isListed) {
                throw new \InvalidArgumentException('Selected bed is not available in this room for the requested dates.');
            }

            return $bed;
        }

        $selected = $availableBeds[0];
        $bed = Bed::query()->find($selected['id']);

        if (! $bed) {
            throw new \InvalidArgumentException('No available bed found for the reserved room on the selected dates.');
        }

        return $bed;
    }

    private function resolveWalkinCustomer(Customer $customer): CustomerWalkin
    {
        $name = strtoupper(trim(collect([
            $customer->first_name,
            $customer->middle_name,
            $customer->last_name,
        ])->filter()->implode(' ')));

        $idPresented = trim((string) ($customer->sirb_no ?: ''));

        if ($idPresented === '') {
            $idPresented = 'CUSTOMER-' . $customer->id;
        }

        return CustomerWalkin::query()->firstOrCreate(
            ['id_presented' => $idPresented],
            [
                'name' => $name !== '' ? $name : 'CUSTOMER ' . $customer->id,
                'status' => 0,
                'user_id' => $customer->user_id,
            ]
        );
    }
}
