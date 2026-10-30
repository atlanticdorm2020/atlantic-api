<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['location', 'user'])
            ->when($request->location_id, fn ($builder) => $builder->where('location_id', $request->integer('location_id')))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');

                $builder->where(function ($inner) use ($search) {
                    $inner->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('sirb_no', 'like', "%{$search}%")
                        ->orWhere('mobile_no', 'like', "%{$search}%")
                        ->orWhere('rank', 'like', "%{$search}%")
                        ->orWhereHas('location', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"));
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
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
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
            'note' => ['required', 'string', 'max:191'],
            'status' => ['nullable', 'integer'],
            'balance' => ['nullable', 'integer'],
            'penalty_amount' => ['nullable', 'numeric'],
        ]);

        $customer = Customer::create($this->withActor($data, $request));

        return $this->success($customer->load(['location', 'user']), 'Customer created.', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->success($customer->load([
            'location',
            'user',
            'transactions.bed.room',
            'files',
            'penalties',
            'balances',
        ]));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'first_name' => ['sometimes', 'required', 'string', 'max:191'],
            'last_name' => ['sometimes', 'required', 'string', 'max:191'],
            'middle_name' => ['nullable', 'string', 'max:191'],
            'sirb_no' => ['sometimes', 'required', 'string', 'max:191'],
            'mobile_no' => ['sometimes', 'required', 'string', 'max:191'],
            'permanent_address' => ['sometimes', 'required', 'string', 'max:191'],
            'rank' => ['sometimes', 'required', 'string', 'max:191'],
            'agency' => ['sometimes', 'required', 'string', 'max:191'],
            'icoe_name' => ['sometimes', 'required', 'string', 'max:191'],
            'icoe_relation' => ['sometimes', 'required', 'string', 'max:191'],
            'icoe_contact' => ['sometimes', 'required', 'string', 'max:191'],
            'note' => ['sometimes', 'required', 'string', 'max:191'],
            'status' => ['nullable', 'integer'],
            'balance' => ['nullable', 'integer'],
            'penalty_amount' => ['nullable', 'numeric'],
        ]);

        $customer->update($this->withActor($data, $request));

        return $this->success($customer->fresh()->load(['location', 'user']), 'Customer updated.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return $this->success(null, 'Customer deleted.');
    }

    public function updateCredentials(Request $request, Customer $customer): JsonResponse
    {
        $customer->loadMissing('user');
        $hasUser = $customer->user !== null;

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($customer->user_id),
            ],
            'password' => [$hasUser ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = DB::transaction(function () use ($customer, $data, $hasUser) {
            $name = trim(collect([
                $customer->first_name,
                $customer->middle_name,
                $customer->last_name,
            ])->filter()->implode(' '));

            if ($hasUser) {
                $user = $customer->user;
                $user->email = $data['email'];

                if (! empty($data['password'])) {
                    $user->password = $data['password'];
                }

                $user->save();
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'location_id' => $customer->location_id ?? 0,
                    'role' => User::ROLE_CUSTOMER,
                ]);

                $customer->update(['user_id' => $user->id]);
            }

            return $customer->fresh()->load(['location', 'user']);
        });

        return $this->success($customer, 'Login credentials saved.');
    }

    public function files(Customer $customer): JsonResponse
    {
        $files = $customer->files()
            ->with('user')
            ->latest()
            ->get()
            ->map(fn ($file) => array_merge($file->toArray(), [
                'has_uploaded_file' => $this->customerFileExists($customer, $file->filename),
            ]));

        return $this->success($files);
    }

    public function storeFile(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'],
        ]);

        $uploaded = $request->file('file');
        $extension = $uploaded->getClientOriginalExtension() ?: $uploaded->extension();
        $filename = "{$customer->id}_fileName".time().".{$extension}";

        $this->customerFileDisk()->putFileAs(
            (string) $customer->id,
            $uploaded,
            $filename
        );

        $file = $customer->files()->create($this->withActor([
            'title' => $data['title'],
            'filename' => $filename,
        ], $request));

        return $this->success(
            array_merge($file->load('user')->toArray(), ['has_uploaded_file' => true]),
            'File uploaded successfully.',
            201
        );
    }

    public function downloadFile(Customer $customer, int $file): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $record = $customer->files()->findOrFail($file);
        $resolved = $this->resolveCustomerFilePath($customer, $record->filename);

        if (! $resolved) {
            return $this->error('File not found on server.', 404);
        }

        if ($resolved['absolute']) {
            return response()->download($resolved['path'], $record->filename);
        }

        return $this->customerFileDisk()->download($resolved['path'], $record->filename);
    }

    public function destroyFile(Customer $customer, int $file): JsonResponse
    {
        $record = $customer->files()->findOrFail($file);
        $resolved = $this->resolveCustomerFilePath($customer, $record->filename);

        if ($resolved && ! $resolved['absolute']) {
            $this->customerFileDisk()->delete($resolved['path']);
        }

        $record->delete();

        return $this->success(null, 'File deleted.');
    }

    public function balances(Customer $customer): JsonResponse
    {
        return $this->success([
            'account_balance' => $customer->balance,
            'penalty_amount' => $customer->penalty_amount,
            'records' => $customer->balances()->with(['location', 'room'])->latest()->get(),
        ]);
    }

    public function updateBalance(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'balance' => ['required', 'integer'],
            'penalty_amount' => ['nullable', 'numeric'],
        ]);

        $customer->update($this->withActor($data, $request));

        return $this->success($customer->fresh(), 'Balance updated.');
    }

    public function history(Customer $customer): JsonResponse
    {
        return $this->success([
            'transactions' => $customer->transactions()
                ->with(['location', 'bed.room', 'histories'])
                ->latest()
                ->get(),
            'bed_histories' => $customer->bedHistories()
                ->with(['bed.room.location', 'transaction'])
                ->latest()
                ->get(),
        ]);
    }

    protected function customerFileDisk()
    {
        return Storage::disk('customer_files');
    }

    protected function customerFileExists(Customer $customer, string $filename): bool
    {
        return $this->resolveCustomerFilePath($customer, $filename) !== null;
    }

    protected function resolveCustomerFilePath(Customer $customer, string $filename): ?array
    {
        $disk = $this->customerFileDisk();
        $customerPath = "{$customer->id}/{$filename}";

        if ($disk->exists($customerPath)) {
            return ['path' => $customerPath, 'absolute' => false];
        }

        $legacyPath = "legacy/{$filename}";

        if ($disk->exists($legacyPath)) {
            return ['path' => $legacyPath, 'absolute' => false];
        }

        $flatLegacyPath = $filename;

        if ($disk->exists($flatLegacyPath)) {
            return ['path' => $flatLegacyPath, 'absolute' => false];
        }

        $externalLegacyRoot = env('CUSTOMER_FILES_LEGACY_PATH');

        if ($externalLegacyRoot) {
            $absolutePath = rtrim($externalLegacyRoot, '\\/').DIRECTORY_SEPARATOR.$filename;

            if (is_file($absolutePath)) {
                return ['path' => $absolutePath, 'absolute' => true];
            }
        }

        return null;
    }
}
