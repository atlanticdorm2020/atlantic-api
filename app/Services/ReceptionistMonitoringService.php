<?php

namespace App\Services;

use App\Models\CustomerFile;
use App\Models\Location;
use App\Models\ReceptionistDuty;
use App\Models\Transaction;
use App\Models\TransactionWalkin;
use App\Models\User;
use App\Support\BranchColor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReceptionistMonitoringService
{
    public const TIMEZONE = 'Asia/Manila';

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters, ?int $scopedLocationId): array
    {
        $timezone = self::TIMEZONE;
        $range = $this->dateRange($filters, $timezone);
        $collectionEnd = $range['end']->copy()->addDay();
        $allRows = $this->collectRows($filters, $scopedLocationId, $range['start'], $collectionEnd);
        $displayRows = $allRows->filter(function (array $row) use ($range) {
            if (! $row['checked_in_at']) {
                return false;
            }

            $checkedIn = Carbon::parse($row['checked_in_at'], self::TIMEZONE);

            return $checkedIn->betweenIncluded($range['start'], $range['end']);
        })->values();
        $filtered = $this->applyRowFilters($displayRows, $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));
        $total = $filtered->count();
        $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values();
        $duties = $this->dutySummaries($filters, $scopedLocationId, $range['start'], $range['end'], $allRows);

        return [
            'summary' => [
                'total_checkins' => $filtered->count(),
                'pending_transactions' => $filtered->whereIn('status_key', ['pending', 'in_progress'])->count(),
                'missing_files' => $filtered->where('files_status', 'missing')->count(),
                'missing_prices' => $filtered->where('price_status', 'missing')->count(),
                'receptionists_on_duty' => collect($duties)->where('duty_status', 'active')->count(),
            ],
            'filters' => [
                'date_start' => $range['start']->toDateString(),
                'date_end' => $range['end']->toDateString(),
                'location_id' => $scopedLocationId ?: ($filters['location_id'] ?? null),
            ],
            'items' => $items,
            'duties' => $duties,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function collectRows(array $filters, ?int $scopedLocationId, Carbon $start, Carbon $end): Collection
    {
        $locationId = $scopedLocationId ?: ($filters['location_id'] ?? null);
        $receptionistId = $filters['receptionist_id'] ?? null;
        $fileCounts = CustomerFile::query()
            ->selectRaw('customer_id, COUNT(*) as files_count')
            ->groupBy('customer_id')
            ->pluck('files_count', 'customer_id');

        $bedspace = Transaction::query()
            ->with(['customer', 'location', 'checkedInBy', 'bed.room'])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->when($receptionistId, fn ($query) => $query->where('checked_in_by', $receptionistId))
            ->whereBetween('login', [$start, $end])
            ->latest('login')
            ->get()
            ->map(function (Transaction $transaction) use ($fileCounts) {
                $filesCount = (int) ($fileCounts[$transaction->customer_id] ?? 0);
                $filesStatus = $filesCount > 0 ? 'complete' : 'missing';
                $priceStatus = $transaction->rates === null ? 'missing' : 'complete';
                $statusKey = $this->bedspaceStatusKey($transaction->status);

                return $this->formatRow(
                    source: 'bedspace',
                    transaction: $transaction,
                    customerName: $this->customerName($transaction->customer),
                    receptionist: $transaction->checkedInBy,
                    filesStatus: $filesStatus,
                    filesCount: $filesCount,
                    priceStatus: $priceStatus,
                    statusKey: $statusKey,
                    transactionLabel: $transaction->bed?->room?->name
                        ? 'Bedspace · '.$transaction->bed->room->name
                        : 'Bedspace stay',
                );
            });

        $walkins = TransactionWalkin::query()
            ->with(['customer', 'location', 'receptionist', 'room'])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->when($receptionistId, fn ($query) => $query->where('user_id', $receptionistId))
            ->whereBetween('login', [$start, $end])
            ->latest('login')
            ->get()
            ->map(function (TransactionWalkin $transaction) {
                $statusKey = ((int) $transaction->status) === 1 ? 'in_progress' : 'completed';

                return $this->formatRow(
                    source: 'walkin',
                    transaction: $transaction,
                    customerName: strtoupper(trim($transaction->customer?->name ?? '—')),
                    receptionist: $transaction->receptionist,
                    filesStatus: 'not_required',
                    filesCount: 0,
                    priceStatus: $transaction->rates === null ? 'missing' : 'complete',
                    statusKey: $statusKey,
                    transactionLabel: $transaction->room?->name
                        ? 'Walk-in · '.$transaction->room->name
                        : 'Walk-in stay',
                );
            });

        return $bedspace->concat($walkins)
            ->sortByDesc(fn (array $row) => $row['checked_in_at'] ?? '')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function applyRowFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when(($filters['status'] ?? '') !== '', fn (Collection $items) => $items->where('status_key', $filters['status'])->values())
            ->when(($filters['missing_files'] ?? false), fn (Collection $items) => $items->where('files_status', 'missing')->values())
            ->when(($filters['missing_price'] ?? false), fn (Collection $items) => $items->where('price_status', 'missing')->values())
            ->when(($filters['search'] ?? '') !== '', function (Collection $items) use ($filters) {
                $search = strtolower((string) $filters['search']);

                return $items->filter(function (array $row) use ($search) {
                    return str_contains(strtolower((string) $row['customer_name']), $search)
                        || str_contains(strtolower((string) $row['receptionist_name']), $search)
                        || str_contains(strtolower((string) $row['reference']), $search)
                        || str_contains(strtolower((string) $row['branch_name']), $search);
                })->values();
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function dutySummaries(array $filters, ?int $scopedLocationId, Carbon $start, Carbon $end, Collection $rows): array
    {
        $locationId = $scopedLocationId ?: ($filters['location_id'] ?? null);
        $receptionistId = $filters['receptionist_id'] ?? null;

        return ReceptionistDuty::query()
            ->with(['receptionist', 'location'])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->when($receptionistId, fn ($query) => $query->where('user_id', $receptionistId))
            ->overlapping($start->toDateString(), $end->toDateString())
            ->orderBy('duty_date')
            ->orderBy('start_time')
            ->get()
            ->map(function (ReceptionistDuty $duty) use ($rows) {
                $dutyStart = $duty->periodStartsAt();
                $dutyCutoff = $duty->periodCutsOffAt();
                $windowRows = $rows->filter(function (array $row) use ($duty) {
                    if ((int) $row['receptionist_id'] !== (int) $duty->user_id) {
                        return false;
                    }
                    if ((int) $row['location_id'] !== (int) $duty->location_id) {
                        return false;
                    }
                    if (! $row['checked_in_at']) {
                        return false;
                    }

                    return $duty->containsCheckIn(Carbon::parse($row['checked_in_at'], $duty->timezoneName()));
                });

                $pending = $windowRows->whereIn('status_key', ['pending', 'in_progress']);
                $missingFiles = $windowRows->where('files_status', 'missing')->count();
                $missingPrices = $windowRows->where('price_status', 'missing')->count();
                $windowStatus = $duty->windowStatus();
                $dutyStatus = $windowStatus === 'completed' && ($pending->count() || $missingFiles || $missingPrices)
                    ? 'overdue'
                    : $windowStatus;
                $branch = $duty->location;

                return [
                    'id' => $duty->id,
                    'receptionist_id' => $duty->user_id,
                    'receptionist_name' => $duty->receptionist?->name ?? '—',
                    'location_id' => $duty->location_id,
                    'branch_name' => $branch?->name ?? '—',
                    'branch_color' => $branch?->color_hex,
                    'branch_color_text' => $branch?->color_text,
                    'duty_date' => $duty->dateFrom()->toDateString(),
                    'end_date' => $duty->dateTo()->toDateString(),
                    'day_count' => $duty->dayCount(),
                    'start_time' => substr((string) $duty->start_time, 0, 5),
                    'cutoff_time' => substr((string) $duty->cutoff_time, 0, 5),
                    'starts_at' => $dutyStart->toIso8601String(),
                    'cutoff_at' => $dutyCutoff->toIso8601String(),
                    'overnight' => (bool) $duty->overnight,
                    'timezone' => $duty->timezoneName(),
                    'duty_status' => $dutyStatus,
                    'total_checkins' => $windowRows->count(),
                    'pending_transactions' => $pending->count(),
                    'missing_files' => $missingFiles,
                    'missing_prices' => $missingPrices,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function dateRange(array $filters, string $timezone): array
    {
        $today = Carbon::now($timezone)->toDateString();
        $start = Carbon::parse($filters['date_start'] ?? $today, $timezone)->startOfDay();
        $end = Carbon::parse($filters['date_end'] ?? $today, $timezone)->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return ['start' => $start, 'end' => $end];
    }

    public function staff(?int $scopedLocationId): Collection
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->when($scopedLocationId, fn ($query) => $query->where('location_id', $scopedLocationId))
            ->with('location')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'location_id' => $user->location_id,
                'branch_name' => $user->location?->name,
                'branch_color' => $user->location?->color_hex,
            ]);
    }

    /**
     * @param  Transaction|TransactionWalkin  $transaction
     * @return array<string, mixed>
     */
    private function formatRow(
        string $source,
        $transaction,
        string $customerName,
        ?User $receptionist,
        string $filesStatus,
        int $filesCount,
        string $priceStatus,
        string $statusKey,
        string $transactionLabel,
    ): array {
        $location = $transaction->location;
        $colors = BranchColor::payload($location?->color, $location?->name, $location?->id);

        return [
            'id' => $source.'-'.$transaction->id,
            'source' => $source,
            'transaction_id' => $transaction->id,
            'reference' => $transaction->unique_id ?: '#'.$transaction->id,
            'customer_name' => $customerName ?: '—',
            'location_id' => $transaction->location_id,
            'branch_name' => $location?->name ?? '—',
            'branch_color' => $colors['color'],
            'branch_color_text' => $colors['text'],
            'receptionist_id' => $receptionist?->id,
            'receptionist_name' => $receptionist?->name ?? '—',
            'checked_in_at' => $transaction->login?->timezone(self::TIMEZONE)->toIso8601String(),
            'transaction_label' => $transactionLabel,
            'status_key' => $statusKey,
            'status_label' => $this->statusLabel($statusKey),
            'files_status' => $filesStatus,
            'files_count' => $filesCount,
            'price_status' => $priceStatus,
            'rate' => $transaction->rates,
            'updated_at' => $transaction->updated_at?->timezone(self::TIMEZONE)->toIso8601String(),
        ];
    }

    private function bedspaceStatusKey(?string $status): string
    {
        return match ((string) $status) {
            '2' => 'pending',
            '1' => 'in_progress',
            '0' => 'completed',
            '3' => 'cancelled',
            default => 'in_progress',
        };
    }

    private function statusLabel(string $key): string
    {
        return match ($key) {
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    private function customerName($customer): string
    {
        if (! $customer) {
            return '—';
        }

        return strtoupper(trim(
            ($customer->last_name ?? '').', '.trim(($customer->first_name ?? '').' '.($customer->middle_name ?? ''))
        ));
    }
}
