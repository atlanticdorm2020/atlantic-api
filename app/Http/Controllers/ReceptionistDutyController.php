<?php

namespace App\Http\Controllers;

use App\Models\ReceptionistDuty;
use App\Models\ReceptionistDutyHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReceptionistDutyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $filters = $request->validate([
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $timezone = 'Asia/Manila';
        $today = Carbon::now($timezone)->toDateString();
        $start = $filters['date_start'] ?? $today;
        $end = $filters['date_end'] ?? $today;
        $locationId = $this->scopedLocationId($request, $filters['location_id'] ?? null);

        $duties = ReceptionistDuty::query()
            ->with(['receptionist', 'location', 'creator'])
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->when($filters['user_id'] ?? null, fn ($query) => $query->where('user_id', $filters['user_id']))
            ->overlapping($start, $end)
            ->orderBy('duty_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (ReceptionistDuty $duty) => $this->formatDuty($duty));

        return $this->success($duties);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $data = $this->validatedPayload($request);
        if ($rangeError = $this->guardDutyRange($data)) {
            return $rangeError;
        }
        if ($denied = $this->guardDutyUser($data['user_id'] ?? null)) {
            return $denied;
        }
        $data['location_id'] = $this->scopedLocationId($request, $data['location_id'] ?? null) ?? ($data['location_id'] ?? null);
        if (! $data['location_id']) {
            return $this->error('Branch location is required.', 422);
        }
        $data['end_date'] = $data['end_date'] ?? $data['duty_date'];
        $data['created_by'] = $actor->id;
        $data['timezone'] = $data['timezone'] ?? 'Asia/Manila';
        $data['overnight'] = (bool) ($data['overnight'] ?? false);

        $duty = ReceptionistDuty::create($data);
        $this->recordHistory($duty, $actor->id, ['created' => $duty->only($this->trackedFields())]);

        return $this->success($this->formatDuty($duty->load(['receptionist', 'location'])), 'Duty schedule created.', 201);
    }

    public function update(Request $request, ReceptionistDuty $receptionistDuty): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        if ($denied = $this->guardDutyAccess($request, $receptionistDuty)) {
            return $denied;
        }

        $data = $this->validatedPayload($request, true);
        $merged = array_merge($receptionistDuty->only(['duty_date', 'end_date']), $data);
        if ($rangeError = $this->guardDutyRange($merged)) {
            return $rangeError;
        }
        if (array_key_exists('user_id', $data) && ($denied = $this->guardDutyUser($data['user_id']))) {
            return $denied;
        }
        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = $this->scopedLocationId($request, $data['location_id']) ?? $data['location_id'];
        }
        if (array_key_exists('overnight', $data)) {
            $data['overnight'] = (bool) $data['overnight'];
        }

        $before = $receptionistDuty->only($this->trackedFields());
        $receptionistDuty->update($data);
        $after = $receptionistDuty->fresh()->only($this->trackedFields());
        $changes = ['before' => $before, 'after' => $after];
        $this->recordHistory($receptionistDuty, $actor->id, $changes);

        return $this->success($this->formatDuty($receptionistDuty->fresh(['receptionist', 'location'])), 'Duty schedule updated.');
    }

    public function destroy(Request $request, ReceptionistDuty $receptionistDuty): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        if ($denied = $this->guardDutyAccess($request, $receptionistDuty)) {
            return $denied;
        }

        $this->recordHistory($receptionistDuty, $actor->id, ['deleted' => $receptionistDuty->only($this->trackedFields())]);
        $receptionistDuty->delete();

        return $this->success(null, 'Duty schedule removed.');
    }

    public function history(Request $request, ReceptionistDuty $receptionistDuty): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        if ($denied = $this->guardDutyAccess($request, $receptionistDuty)) {
            return $denied;
        }

        $history = $receptionistDuty->histories()
            ->with('changer')
            ->latest('created_at')
            ->get()
            ->map(fn (ReceptionistDutyHistory $item) => [
                'id' => $item->id,
                'changed_by' => $item->changer?->name ?? '—',
                'changes' => $item->changes,
                'created_at' => $item->created_at,
            ]);

        return $this->success($history);
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'user_id' => [$required, 'integer', 'exists:users,id'],
            'location_id' => [$partial ? 'sometimes' : 'nullable', 'integer', 'exists:locations,id'],
            'duty_date' => [$required, 'date'],
            'end_date' => [$partial ? 'sometimes' : 'nullable', 'date'],
            'start_time' => [$required, 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'cutoff_time' => [$required, 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'overnight' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function trackedFields(): array
    {
        return ['user_id', 'location_id', 'duty_date', 'end_date', 'start_time', 'cutoff_time', 'overnight', 'timezone'];
    }

    private function recordHistory(ReceptionistDuty $duty, int $changedBy, array $changes): void
    {
        ReceptionistDutyHistory::create([
            'duty_id' => $duty->id,
            'changed_by' => $changedBy,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    private function formatDuty(ReceptionistDuty $duty): array
    {
        return [
            'id' => $duty->id,
            'user_id' => $duty->user_id,
            'receptionist_name' => $duty->receptionist?->name,
            'location_id' => $duty->location_id,
            'branch_name' => $duty->location?->name,
            'branch_color' => $duty->location?->color_hex,
            'branch_color_text' => $duty->location?->color_text,
            'duty_date' => $duty->dateFrom()->toDateString(),
            'end_date' => $duty->dateTo()->toDateString(),
            'day_count' => $duty->dayCount(),
            'start_time' => substr((string) $duty->start_time, 0, 5),
            'cutoff_time' => substr((string) $duty->cutoff_time, 0, 5),
            'overnight' => (bool) $duty->overnight,
            'timezone' => $duty->timezoneName(),
            'duty_status' => $duty->windowStatus(),
            'starts_at' => $duty->periodStartsAt()->toIso8601String(),
            'cutoff_at' => $duty->periodCutsOffAt()->toIso8601String(),
        ];
    }

    private function requireAdmin(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return $this->error('Administrator access is required.', 403);
        }

        return $user;
    }

    private function scopedLocationId(Request $request, mixed $requestedLocationId = null): ?int
    {
        $assigned = $request->user()?->location_id ? (int) $request->user()->location_id : null;
        if ($assigned) {
            return $assigned;
        }

        return $requestedLocationId ? (int) $requestedLocationId : null;
    }

    private function guardDutyRange(array $data): ?JsonResponse
    {
        if (empty($data['duty_date'])) {
            return null;
        }

        $from = Carbon::parse($data['duty_date'])->startOfDay();
        $to = Carbon::parse($data['end_date'] ?? $data['duty_date'])->startOfDay();

        if ($to->lt($from)) {
            return $this->error('Duty end date must be on or after the start date.', 422);
        }

        if (($from->diffInDays($to) + 1) > ReceptionistDuty::MAX_DAYS) {
            return $this->error('A duty schedule can cover at most 30 days.', 422);
        }

        return null;
    }

    private function guardDutyUser(mixed $userId): ?JsonResponse
    {
        if (! $userId) {
            return null;
        }

        $assignee = User::find($userId);
        if (! $assignee?->isAdmin()) {
            return $this->error('Duty can only be assigned to an administrator or receptionist.', 422);
        }

        return null;
    }

    private function guardDutyAccess(Request $request, ReceptionistDuty $duty): ?JsonResponse
    {
        $assigned = $request->user()?->location_id ? (int) $request->user()->location_id : null;
        if ($assigned && (int) $duty->location_id !== $assigned) {
            return $this->error('You cannot manage another branch duty schedule.', 403);
        }

        return null;
    }
}
