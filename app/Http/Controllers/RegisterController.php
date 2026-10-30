<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'privilege' => ['nullable', 'integer'],
            'role' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'location_id' => $data['location_id'] ?? null,
            'privilege' => $data['privilege'] ?? null,
            'role' => $data['role'] ?? User::ROLE_CUSTOMER,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success([
            'user' => $user->load('location'),
            'token' => $token,
        ], 'Registration successful.', 201);
    }

    public function registerCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'location_id' => ['nullable', 'integer', 'min:0'],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'middle_name' => ['nullable', 'string', 'max:191'],
            'sirb_no' => ['required', 'string', 'max:191'],
            'mobile_no' => ['required', 'string', 'max:191'],
            'permanent_address' => ['required', 'string', 'max:191'],
            'rank' => ['required', 'string', 'max:191'],
            'agency' => ['required', 'string', 'max:191'],
            'icoe_name' => ['required', 'string', 'max:191'],
            'icoe_relation' => ['required', 'string', 'max:191'],
            'icoe_contact' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $name = trim(collect([
                $data['first_name'],
                $data['middle_name'] ?? null,
                $data['last_name'],
            ])->filter()->implode(' '));

            $user = User::create([
                'name' => $name,
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'location_id' => $data['location_id'] ?? 0,
                'role' => User::ROLE_CUSTOMER,
            ]);

            $customer = Customer::create([
                'user_id' => $user->id,
                'location_id' => $data['location_id'] ?? 0,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'sirb_no' => $data['sirb_no'],
                'mobile_no' => $data['mobile_no'],
                'permanent_address' => $data['permanent_address'],
                'rank' => $data['rank'],
                'agency' => $data['agency'],
                'icoe_name' => $data['icoe_name'],
                'icoe_relation' => $data['icoe_relation'],
                'icoe_contact' => $data['icoe_contact'],
                'note' => $data['note'] ?? '',
                'status' => 0,
                'balance' => 0,
            ]);

            $token = $user->createToken('api-token')->plainTextToken;

            return [
                'user' => $user->load(['location', 'customerProfile']),
                'customer' => $customer->load('location'),
                'token' => $token,
            ];
        });

        return $this->success($result, 'Customer registration successful.', 201);
    }
}
