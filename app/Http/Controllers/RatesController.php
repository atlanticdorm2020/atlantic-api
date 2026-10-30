<?php

namespace App\Http\Controllers;

use App\Models\Rate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Rate::with(['locations', 'rooms', 'customRates', 'user'])
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where('amount', 'like', "%{$search}%");
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
            'amount' => ['required', 'numeric'],
            'status' => ['nullable', 'integer'],
        ]);

        $rate = Rate::create($this->withActor($data, $request));

        return $this->success($rate->load('user'), 'Rate created.', 201);
    }

    public function show(Rate $rate): JsonResponse
    {
        return $this->success($rate->load(['locations', 'rooms', 'customRates']));
    }

    public function update(Request $request, Rate $rate): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric'],
            'status' => ['nullable', 'integer'],
        ]);

        $rate->update($this->withActor($data, $request));

        return $this->success($rate->fresh()->load('user'), 'Rate updated.');
    }

    public function destroy(Rate $rate): JsonResponse
    {
        $rate->delete();

        return $this->success(null, 'Rate deleted.');
    }
}
