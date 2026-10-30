<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Room::with(['location', 'rate', 'beds', 'user'])
            ->when($request->location_id, fn ($builder) => $builder->where('location_id', $request->integer('location_id')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhereHas('location', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('ordered');

        if ($request->boolean('all')) {
            return $this->success($query->get());
        }

        return $this->paginatedResponse($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
            'is_bedspace' => ['required', 'integer'],
            'status' => ['nullable', 'integer'],
            'ordered' => ['required', 'integer'],
        ]);

        $room = Room::create($this->withActor($data, $request));

        return $this->success($room->load(['location', 'rate', 'user']), 'Room created.', 201);
    }

    public function show(Room $room): JsonResponse
    {
        return $this->success($room->load(['location', 'rate', 'beds']));
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'location_id' => ['sometimes', 'required', 'integer', 'exists:locations,id'],
            'rate_id' => ['sometimes', 'required', 'integer', 'exists:rates,id'],
            'is_bedspace' => ['sometimes', 'required', 'integer'],
            'status' => ['nullable', 'integer'],
            'ordered' => ['sometimes', 'required', 'integer'],
        ]);

        $room->update($this->withActor($data, $request));

        return $this->success($room->fresh()->load(['location', 'rate', 'beds', 'user']), 'Room updated.');
    }

    public function destroy(Room $room): JsonResponse
    {
        $room->delete();

        return $this->success(null, 'Room deleted.');
    }
}
