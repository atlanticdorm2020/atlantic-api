<?php

namespace App\Http\Controllers;

use App\Models\CustomRate;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

class PublicBranchController extends Controller
{
    private const BRANCH_SLUGS = [
        'adriatico' => 4,
        'modesto' => 3,
        'mabini' => 5,
    ];

    public function rates(string $slug): JsonResponse
    {
        $normalizedSlug = strtolower(trim($slug));
        $location = $this->resolveLocation($normalizedSlug);

        if (! $location) {
            return $this->error('Branch not found.', 404);
        }

        $rooms = Room::query()
            ->with('rate')
            ->where('location_id', $location->id)
            ->where('status', 1)
            ->orderByDesc('is_bedspace')
            ->orderBy('ordered')
            ->get();

        $roomRates = $rooms
            ->map(function (Room $room) {
                $isBedspace = (int) $room->is_bedspace === 1;

                return [
                    'name' => $isBedspace ? 'Bedspace' : 'Private room',
                    'type' => $isBedspace ? 'bedspace' : 'room',
                    'amount' => (float) ($room->rate?->amount ?? 0),
                    'unit' => $isBedspace ? 'day' : 'night',
                ];
            })
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->unique(fn (array $row) => $row['type'].'|'.$row['amount'])
            ->values();

        $walkinRates = CustomRate::query()
            ->with('rate')
            ->where('location_id', $location->id)
            ->where('status', 1)
            ->orderBy('type')
            ->orderBy('hours')
            ->get()
            ->map(fn (CustomRate $customRate) => [
                'name' => sprintf(
                    '%d hours · %s',
                    (int) $customRate->hours,
                    (int) $customRate->type === 1 ? 'Walk-in couple' : 'With agent'
                ),
                'type' => 'walkin',
                'amount' => (float) ($customRate->rate?->amount ?? 0),
                'unit' => 'stay',
            ])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->values();

        return $this->success([
            'branch' => [
                'id' => $location->id,
                'name' => $location->name,
            ],
            'room_rates' => $roomRates,
            'walkin_rates' => $walkinRates,
        ]);
    }

    private function resolveLocation(string $slug): ?Location
    {
        $mappedId = self::BRANCH_SLUGS[$slug] ?? null;

        if ($mappedId) {
            $location = Location::query()->whereKey($mappedId)->first();

            if ($location) {
                return $location;
            }
        }

        return Location::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$slug.'%'])
            ->first();
    }
}
