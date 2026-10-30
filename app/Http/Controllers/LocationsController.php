<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Location::with(['rate', 'rooms', 'user'])
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhereHas('rate', fn ($rateQuery) => $rateQuery->where('amount', 'like', "%{$search}%"));
                });
            })
            ->latest();

        if ($request->boolean('all')) {
            return $this->success($query->get());
        }

        return $this->paginatedResponse($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:191'],
            'status' => ['nullable', 'integer'],
            'rate_id' => ['nullable', 'integer', 'exists:rates,id'],
        ]);

        $location = Location::create($this->withActor($data, $request));

        return $this->success($location->load(['rate', 'user']), 'Location created.', 201);
    }

    public function show(Location $location): JsonResponse
    {
        return $this->success($location->load(['rate', 'rooms.beds', 'customers', 'customRates']));
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'address' => ['sometimes', 'required', 'string', 'max:191'],
            'status' => ['nullable', 'integer'],
            'rate_id' => ['nullable', 'integer', 'exists:rates,id'],
        ]);

        $location->update($this->withActor($data, $request));

        return $this->success($location->fresh()->load(['rate', 'user']), 'Location updated.');
    }

    public function destroy(Location $location): JsonResponse
    {
        $location->delete();

        return $this->success(null, 'Location deleted.');
    }
}
