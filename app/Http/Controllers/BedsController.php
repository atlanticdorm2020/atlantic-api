<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BedsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bed::with(['room.location', 'user'])
            ->when($request->room_id, fn ($builder) => $builder->where('room_id', $request->integer('room_id')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhereHas('room', function ($roomQuery) use ($search) {
                            $roomQuery->where('name', 'like', "%{$search}%")
                                ->orWhereHas('location', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"));
                        });
                });
            })
            ->orderBy('sort');

        if ($request->boolean('all')) {
            return $this->success($query->get());
        }

        return $this->paginatedResponse($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'status' => ['required', 'integer'],
            'sort' => ['nullable', 'integer'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $bed = Bed::create($this->withActor($data, $request));

        return $this->success($bed->load(['room', 'user']), 'Bed created.', 201);
    }

    public function show(Bed $bed): JsonResponse
    {
        return $this->success($bed->load(['room.location', 'transactions.customer']));
    }

    public function update(Request $request, Bed $bed): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'status' => ['sometimes', 'required', 'integer'],
            'sort' => ['nullable', 'integer'],
            'room_id' => ['sometimes', 'required', 'integer', 'exists:rooms,id'],
        ]);

        $bed->update($this->withActor($data, $request));

        return $this->success($bed->fresh()->load(['room', 'user']), 'Bed updated.');
    }

    public function destroy(Bed $bed): JsonResponse
    {
        $bed->delete();

        return $this->success(null, 'Bed deleted.');
    }
}
