<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAccountController extends Controller
{
    public function __construct(
        private readonly CustomersController $customersController,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request)->load(['location', 'user']);

        return $this->success($this->formatProfile($customer));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request);

        $data = $request->validate([
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
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer->update($data);

        return $this->success(
            $this->formatProfile($customer->fresh()->load(['location', 'user'])),
            'Profile updated successfully.'
        );
    }

    public function transactions(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        $customer = $this->customerForUser($request);

        $transactions = $customer->transactions()
            ->with(['location', 'bed.room'])
            ->latest('login')
            ->paginate($this->perPage($request));

        $transactions->getCollection()->transform(fn ($transaction) => [
            'id' => $transaction->id,
            'reference' => $transaction->unique_id ?? ('#' . $transaction->id),
            'branch' => $transaction->location?->name,
            'room' => $transaction->bed?->room?->name,
            'bed' => $transaction->bed?->name,
            'amount' => (float) ($transaction->final_amount ?? $transaction->amount ?? 0),
            'days' => (int) ($transaction->no_day ?? 0) + (int) ($transaction->extend_day ?? 0),
            'status' => $transaction->status === '1' ? 'Active' : 'Checked out',
            'check_in' => $transaction->login?->toIso8601String(),
            'check_out' => $transaction->logout?->toIso8601String(),
        ]);

        return $this->paginatedResponse($transactions);
    }

    public function balance(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        return $this->customersController->balances($this->customerForUser($request));
    }

    public function files(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        return $this->customersController->files($this->customerForUser($request));
    }

    public function storeFile(Request $request): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        return $this->customersController->storeFile($request, $this->customerForUser($request));
    }

    public function downloadFile(Request $request, int $file): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        return $this->customersController->downloadFile($this->customerForUser($request), $file);
    }

    public function destroyFile(Request $request, int $file): JsonResponse
    {
        if ($response = $this->ensureCustomer($request)) {
            return $response;
        }

        return $this->customersController->destroyFile($this->customerForUser($request), $file);
    }

    private function ensureCustomer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isCustomer()) {
            return $this->error('Only customer accounts can access this page.', 403);
        }

        if (! Customer::where('user_id', $user->id)->exists()) {
            return $this->error('Customer profile not found. Please complete your registration.', 422);
        }

        return null;
    }

    private function customerForUser(Request $request): Customer
    {
        return Customer::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function formatProfile(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'middle_name' => $customer->middle_name,
            'sirb_no' => $customer->sirb_no,
            'mobile_no' => $customer->mobile_no,
            'permanent_address' => $customer->permanent_address,
            'rank' => $customer->rank,
            'agency' => $customer->agency,
            'icoe_name' => $customer->icoe_name,
            'icoe_relation' => $customer->icoe_relation,
            'icoe_contact' => $customer->icoe_contact,
            'note' => $customer->note,
            'balance' => (int) ($customer->balance ?? 0),
            'penalty_amount' => (float) ($customer->penalty_amount ?? 0),
            'email' => $customer->user?->email,
            'location' => $customer->location?->only(['id', 'name']),
        ];
    }
}
