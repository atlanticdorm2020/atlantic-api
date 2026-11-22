<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Services\ReceptionistMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function __construct(private readonly ReceptionistMonitoringService $monitoring)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $filters = $request->validate([
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'receptionist_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'in:pending,in_progress,completed,cancelled'],
            'missing_files' => ['nullable', 'boolean'],
            'missing_price' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['missing_files'] = $request->boolean('missing_files');
        $filters['missing_price'] = $request->boolean('missing_price');

        $scopedLocationId = $this->scopedLocationId($request, $filters['location_id'] ?? null);

        return $this->success($this->monitoring->dashboard($filters, $scopedLocationId));
    }

    public function staff(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        return $this->success($this->monitoring->staff($this->scopedLocationId($request)));
    }

    public function branches(Request $request): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }

        $locationId = $this->scopedLocationId($request);

        $branches = Location::query()
            ->when($locationId, fn ($query) => $query->whereKey($locationId))
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'color' => $location->color_hex,
                'color_text' => $location->color_text,
            ]);

        return $this->success($branches);
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
}
