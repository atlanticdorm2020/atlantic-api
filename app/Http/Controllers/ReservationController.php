<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\CustomRate;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Transaction;
use App\Services\ReservationApprovalService;
use App\Services\ReservationAvailabilityService;
use App\Services\TransactionPricing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    private const RESERVATION_LOCATION_IDS = [4, 3, 5];

    public function __construct(
        private readonly TransactionPricing $pricing,
        private readonly ReservationAvailabilityService $availability,
        private readonly ReservationApprovalService $approval,
    ) {}

    public function formOptions(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $data = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'type' => ['nullable', 'string', Rule::in([Reservation::TYPE_TRANSACTION, Reservation::TYPE_WALKIN])],
            'preferred_check_in' => ['nullable', 'date'],
            'no_day' => ['nullable', 'integer', 'min:1'],
            'hours' => ['nullable', 'integer', 'min:1'],
        ]);

        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $type = $data['type'] ?? null;
        $checkIn = isset($data['preferred_check_in'])
            ? Carbon::parse($data['preferred_check_in'])->timezone('Asia/Manila')
            : null;

        $locations = Location::query()
            ->where('status', 1)
            ->whereIn('id', self::RESERVATION_LOCATION_IDS)
            ->get(['id', 'name', 'address'])
            ->sortBy(fn (Location $location) => array_search($location->id, self::RESERVATION_LOCATION_IDS, true))
            ->values()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $this->reservationLocationLabel($location),
                'address' => $location->address,
            ]);

        $rooms = collect();

        if ($locationId && $type === Reservation::TYPE_TRANSACTION && $checkIn) {
            $rooms = collect($this->availability->availableBedspaceRooms(
                $locationId,
                $checkIn,
                (int) ($data['no_day'] ?? 1),
            ));
        }

        if ($locationId && $type === Reservation::TYPE_WALKIN && $checkIn) {
            $rooms = collect($this->availability->availableWalkinRooms(
                $locationId,
                $checkIn,
                (int) ($data['hours'] ?? 3),
            ));
        }

        return $this->success([
            'locations' => $locations,
            'rooms' => $rooms,
            'transaction_types' => $this->transactionTypeOptions(),
        ]);
    }

    public function rateOptions(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'transaction_type' => ['required', 'integer', 'in:0,1'],
        ]);

        return $this->success([
            'rates' => $this->mapCustomRates((int) $data['location_id'], (int) $data['transaction_type']),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in([Reservation::TYPE_TRANSACTION, Reservation::TYPE_WALKIN])],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'no_day' => ['nullable', 'integer', 'min:1'],
            'custom_rate_id' => ['nullable', 'integer', 'exists:custom_rates,id'],
        ]);

        $locationId = (int) $data['location_id'];

        if ($data['type'] === Reservation::TYPE_WALKIN) {
            return $this->previewWalkin($locationId, $data);
        }

        return $this->previewTransaction($customer, $locationId, $data);
    }

    private function previewWalkin(int $locationId, array $data): JsonResponse
    {
        $estimatedAmount = null;
        $hours = null;

        if ($data['custom_rate_id'] ?? null) {
            $customRate = CustomRate::query()
                ->with('rate')
                ->where('location_id', $locationId)
                ->findOrFail($data['custom_rate_id']);

            $hours = (int) $customRate->hours;
            $estimatedAmount = (float) ($customRate->rate?->amount ?? 0);
        }

        return $this->success([
            'estimated_amount' => $estimatedAmount,
            'hours' => $hours,
            'min_days' => $this->pricing->minDaysWithoutBalance(),
        ]);
    }

    private function previewTransaction(Customer $customer, int $locationId, array $data): JsonResponse
    {
        $balanceContext = $this->resolveBalancePreview(
            $customer,
            $locationId,
            isset($data['room_id']) ? (int) $data['room_id'] : null,
        );

        $hasRemainingBalance = $balanceContext['has_remaining_balance'];
        $requestedDays = (int) ($data['no_day'] ?? 0);
        $noDay = $hasRemainingBalance ? $balanceContext['remaining_balance'] : $requestedDays;
        $estimatedAmount = null;
        $rate = null;

        if (! $hasRemainingBalance && $noDay < 1) {
            return $this->success([
                ...$balanceContext,
                'estimated_amount' => null,
                'rate' => null,
                'no_day' => null,
                'hours' => null,
                'min_days' => $this->pricing->minDaysWithoutBalance(),
            ]);
        }

        $effectiveRoomId = $data['room_id'] ?? $balanceContext['suggested_room_id'];

        if ($effectiveRoomId) {
            $room = Room::query()
                ->with('rate')
                ->where('location_id', $locationId)
                ->where('is_bedspace', 1)
                ->findOrFail($effectiveRoomId);

            $rate = (float) ($room->rate?->amount ?? 0);
            $pricing = $this->pricing->resolve(
                $noDay,
                $rate,
                $hasRemainingBalance ? $balanceContext['remaining_balance'] : 0,
                $balanceContext['previous_room_rate'] > 0 ? $balanceContext['previous_room_rate'] : null,
            );
            $estimatedAmount = $pricing['amount'];
            $noDay = $pricing['no_day'];
        }

        return $this->success([
            ...$balanceContext,
            'estimated_amount' => $estimatedAmount,
            'rate' => $rate,
            'no_day' => $noDay > 0 ? $noDay : null,
            'hours' => null,
            'min_days' => $hasRemainingBalance ? 1 : $this->pricing->minDaysWithoutBalance(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request);

        $reservations = Reservation::query()
            ->with(['location', 'room', 'customRate.rate'])
            ->where('customer_id', $customer->id)
            ->latest()
            ->paginate($this->perPage($request));

        $reservations->getCollection()->transform(
            fn (Reservation $reservation) => $this->formatReservation($reservation)
        );

        return $this->paginatedResponse($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in([Reservation::TYPE_TRANSACTION, Reservation::TYPE_WALKIN])],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'preferred_check_in' => ['required', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'no_day' => ['nullable', 'integer', 'min:1'],
            'transaction_type' => ['nullable', 'integer', 'in:0,1'],
            'custom_rate_id' => ['nullable', 'integer', 'exists:custom_rates,id'],
        ]);

        $locationId = (int) $data['location_id'];

        if (! in_array($locationId, self::RESERVATION_LOCATION_IDS, true)) {
            return $this->error('Please select Adriatico, Modesto, or Mabini branch.', 422);
        }

        $payload = [
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'location_id' => $locationId,
            'type' => $data['type'],
            'preferred_check_in' => $data['preferred_check_in'],
            'note' => $data['note'] ?? null,
            'status' => Reservation::STATUS_PENDING,
        ];

        if ($data['type'] === Reservation::TYPE_TRANSACTION) {
            $balanceContext = $this->resolveBalancePreview(
                $customer,
                $locationId,
                isset($data['room_id']) ? (int) $data['room_id'] : null,
            );
            $hasRemainingBalance = $balanceContext['has_remaining_balance'];
            $noDay = $hasRemainingBalance
                ? $balanceContext['remaining_balance']
                : (int) ($data['no_day'] ?? 0);

            if (! $hasRemainingBalance && $noDay < $this->pricing->minDaysWithoutBalance()) {
                return $this->error(
                    'Bedspace reservations require at least ' . $this->pricing->minDaysWithoutBalance() . ' days.',
                    422
                );
            }

            if ($hasRemainingBalance && $noDay < 1) {
                return $this->error('Unable to resolve prepaid balance days for this branch.', 422);
            }

            $payload['no_day'] = $noDay;

            $effectiveRoomId = $data['room_id'] ?? $balanceContext['suggested_room_id'];

            if ($effectiveRoomId) {
                $room = Room::query()
                    ->with('rate')
                    ->where('location_id', $locationId)
                    ->where('is_bedspace', 1)
                    ->findOrFail($effectiveRoomId);

                $payload['room_id'] = $room->id;
                $rate = (float) ($room->rate?->amount ?? 0);
                $pricing = $this->pricing->resolve(
                    $noDay,
                    $rate,
                    $hasRemainingBalance ? $balanceContext['remaining_balance'] : 0,
                    $balanceContext['previous_room_rate'] > 0 ? $balanceContext['previous_room_rate'] : null,
                );
                $payload['no_day'] = $pricing['no_day'];
                $payload['estimated_amount'] = $pricing['amount'];
            }
        }

        if ($data['type'] === Reservation::TYPE_WALKIN) {
            if (! array_key_exists('room_id', $data) || ! $data['room_id']) {
                return $this->error('Room is required for walk-in reservations.', 422);
            }

            if (! array_key_exists('transaction_type', $data) || $data['transaction_type'] === null) {
                return $this->error('Transaction type is required for walk-in reservations.', 422);
            }

            if (! array_key_exists('custom_rate_id', $data) || ! $data['custom_rate_id']) {
                return $this->error('Rate package is required for walk-in reservations.', 422);
            }

            $room = Room::query()
                ->where('location_id', $locationId)
                ->where('is_bedspace', 0)
                ->findOrFail($data['room_id']);

            $customRate = CustomRate::query()
                ->with('rate')
                ->where('location_id', $locationId)
                ->where('type', (int) $data['transaction_type'])
                ->findOrFail($data['custom_rate_id']);

            $payload['room_id'] = $room->id;
            $payload['transaction_type'] = (int) $data['transaction_type'];
            $payload['custom_rate_id'] = $customRate->id;
            $payload['hours'] = (int) $customRate->hours;
            $payload['estimated_amount'] = (float) ($customRate->rate?->amount ?? 0);
        }

        $reservation = Reservation::create($payload)->load(['location', 'room', 'customRate.rate']);

        return $this->success(
            $this->formatReservation($reservation),
            'Reservation request submitted successfully.',
            201
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        $locationId = $request->user()?->location_id;

        $query = Reservation::query()
            ->with(['location', 'room', 'customRate.rate', 'customer.user'])
            ->when($locationId, fn ($builder) => $builder->where('location_id', $locationId))
            ->when($request->filled('location_id'), fn ($builder) => $builder->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', function ($builder) use ($request) {
                $builder->where('status', (int) $request->input('status'));
            })
            ->when($request->filled('type'), fn ($builder) => $builder->where('type', $request->string('type')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($inner) use ($search) {
                    $inner->whereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('sirb_no', 'like', "%{$search}%")
                            ->orWhere('mobile_no', 'like', "%{$search}%");
                    })->orWhereHas('room', fn ($roomQuery) => $roomQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest();

        $paginator = $query->paginate($this->perPage($request));
        $paginator->getCollection()->transform(
            fn (Reservation $reservation) => $this->formatAdminReservation($reservation)
        );

        return $this->paginatedResponse($paginator);
    }

    public function approve(Request $request, Reservation $reservation): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        if ($response = $this->ensureReservationAccessible($request, $reservation)) {
            return $response;
        }

        $data = $request->validate([
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
        ]);

        try {
            $result = $this->approval->approve(
                $reservation,
                (int) $request->user()->id,
                isset($data['bed_id']) ? (int) $data['bed_id'] : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'reservation' => $this->formatAdminReservation($result['reservation']),
            'transaction' => $this->formatApprovedTransaction($result['transaction']),
        ], 'Reservation approved and transaction created.');
    }

    public function approvalPreview(Request $request, Reservation $reservation): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        if ($response = $this->ensureReservationAccessible($request, $reservation)) {
            return $response;
        }

        $availableBeds = $this->availability->availableBedsForReservation($reservation);

        return $this->success([
            'reservation_id' => $reservation->id,
            'room' => $reservation->room?->only(['id', 'name']),
            'available_beds' => $availableBeds,
            'available_beds_count' => count($availableBeds),
            'can_approve' => (int) $reservation->status === Reservation::STATUS_PENDING
                && $reservation->room_id
                && count($availableBeds) > 0,
        ]);
    }

    public function reject(Request $request, Reservation $reservation): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        if ($response = $this->ensureReservationAccessible($request, $reservation)) {
            return $response;
        }

        try {
            $updated = $this->approval->reject($reservation);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            $this->formatAdminReservation($updated),
            'Reservation rejected.'
        );
    }

    private function reservationLocationLabel(Location $location): string
    {
        return match ($location->id) {
            4 => 'Adriatico',
            3 => 'Modesto',
            5 => 'Mabini',
            default => preg_replace('/\s+Branch$/', '', $location->name) ?: $location->name,
        };
    }

    private function ensureCustomer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isCustomer()) {
            return $this->error('Only customer accounts can access reservations.', 403);
        }

        if (! Customer::where('user_id', $user->id)->exists()) {
            return $this->error('Customer profile not found. Please complete your registration.', 422);
        }

        return null;
    }

    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return $this->error('Only administrators can manage reservations.', 403);
        }

        return null;
    }

    private function ensureReservationAccessible(Request $request, Reservation $reservation): ?JsonResponse
    {
        $locationId = $request->user()?->location_id;

        if ($locationId && (int) $reservation->location_id !== (int) $locationId) {
            return $this->error('This reservation belongs to another branch.', 403);
        }

        return null;
    }

    private function customerForUser(Request $request): Customer
    {
        return Customer::where('user_id', $request->user()->id)->firstOrFail();
    }

    /**
     * @return array{
     *     remaining_balance: int,
     *     has_remaining_balance: bool,
     *     upgrade_penalty: float,
     *     suggested_room_id: int|null,
     *     previous_room_rate: float
     * }
     */
    private function resolveBalancePreview(Customer $customer, int $locationId, ?int $roomId = null): array
    {
        $previousTransaction = Transaction::query()
            ->with('bed')
            ->where('customer_id', $customer->id)
            ->where('location_id', $locationId)
            ->latest()
            ->first();

        $balanceRecord = Balance::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->where('location_id', $locationId)
            ->latest('balance_id')
            ->first();

        $remainingBalance = (int) ($balanceRecord?->balance ?? $customer->balance ?? 0);
        $hasRemainingBalance = $remainingBalance > 0;
        $suggestedRoomId = null;

        if ($hasRemainingBalance) {
            $suggestedRoomId = $balanceRecord?->room_id ?? $previousTransaction?->bed?->room_id;
        }

        $effectiveRoomId = $roomId ?? $suggestedRoomId;
        $roomRate = null;
        $upgradePenalty = 0.0;
        $previousRoomRate = (float) ($previousTransaction?->rates ?? 0);

        if ($effectiveRoomId) {
            $room = Room::with('rate')->find($effectiveRoomId);

            if ($room) {
                $roomRate = (float) ($room->rate?->amount ?? 0);
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

        return [
            'remaining_balance' => $remainingBalance,
            'has_remaining_balance' => $hasRemainingBalance,
            'upgrade_penalty' => max(0, $upgradePenalty),
            'suggested_room_id' => $suggestedRoomId ? (int) $suggestedRoomId : null,
            'previous_room_rate' => $previousRoomRate,
        ];
    }

    private function transactionTypeOptions(): array
    {
        return [
            ['id' => 0, 'label' => 'With Agent'],
            ['id' => 1, 'label' => 'Walk In Couple'],
        ];
    }

    private function mapCustomRates(int $locationId, int $transactionType)
    {
        return CustomRate::query()
            ->with('rate')
            ->where('location_id', $locationId)
            ->where('type', $transactionType)
            ->where('status', 1)
            ->orderBy('hours')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomRate $customRate) => [
                'id' => $customRate->id,
                'hours' => $customRate->hours,
                'amount' => (float) ($customRate->rate?->amount ?? 0),
                'label' => sprintf(
                    '%d hours (%s)',
                    (int) $customRate->hours,
                    number_format((float) ($customRate->rate?->amount ?? 0), 2, '.', '')
                ),
            ])
            ->values();
    }

    private function formatReservation(Reservation $reservation): array
    {
        $transactionTypeLabel = null;

        if ($reservation->transaction_type !== null) {
            $transactionTypeLabel = collect($this->transactionTypeOptions())
                ->firstWhere('id', $reservation->transaction_type)['label'] ?? null;
        }

        return [
            'id' => $reservation->id,
            'type' => $reservation->type,
            'type_label' => $reservation->type === Reservation::TYPE_WALKIN ? 'Walk-in Room' : 'Bedspace',
            'location' => $reservation->location?->only(['id', 'name', 'address']),
            'room' => $reservation->room?->only(['id', 'name']),
            'transaction_type' => $reservation->transaction_type,
            'transaction_type_label' => $transactionTypeLabel,
            'custom_rate' => $reservation->customRate ? [
                'id' => $reservation->customRate->id,
                'hours' => $reservation->customRate->hours,
                'amount' => (float) ($reservation->customRate->rate?->amount ?? 0),
            ] : null,
            'no_day' => $reservation->no_day,
            'hours' => $reservation->hours,
            'estimated_amount' => $reservation->estimated_amount,
            'preferred_check_in' => $reservation->preferred_check_in?->toIso8601String(),
            'note' => $reservation->note,
            'status' => $reservation->status,
            'status_label' => match ($reservation->status) {
                Reservation::STATUS_APPROVED => 'Approved',
                Reservation::STATUS_REJECTED => 'Rejected',
                Reservation::STATUS_CANCELLED => 'Cancelled',
                default => 'Pending',
            },
            'created_at' => $reservation->created_at?->toIso8601String(),
        ];
    }

    private function formatAdminReservation(Reservation $reservation): array
    {
        $customer = $reservation->customer;
        $customerName = $customer
            ? trim(collect([
                $customer->last_name,
                $customer->first_name,
                $customer->middle_name,
            ])->filter()->implode(', '))
            : null;

        return [
            ...$this->formatReservation($reservation),
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customerName,
                'email' => $customer->user?->email,
                'sirb_no' => $customer->sirb_no,
                'mobile_no' => $customer->mobile_no,
            ] : null,
            'available_beds_count' => (int) $reservation->status === Reservation::STATUS_PENDING
                ? $this->availability->countAvailableBedsForReservation($reservation)
                : null,
        ];
    }

    private function formatApprovedTransaction(mixed $transaction): array
    {
        if ($transaction instanceof Transaction) {
            return [
                'kind' => 'transaction',
                'id' => $transaction->id,
                'reference' => $transaction->unique_id,
                'status' => $transaction->status,
            ];
        }

        if ($transaction instanceof \App\Models\TransactionWalkin) {
            return [
                'kind' => 'walkin',
                'id' => $transaction->id,
                'reference' => $transaction->unique_id,
                'status' => $transaction->status,
            ];
        }

        return [];
    }
}
