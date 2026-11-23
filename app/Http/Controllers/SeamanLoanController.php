<?php

namespace App\Http\Controllers;

use App\Models\SeamanLoanApplication;
use App\Models\SeamanLoanPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeamanLoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SeamanLoanApplication::query()->with(['payments.recorder'])->latest();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('agency_name', 'like', "%{$search}%")
                    ->orWhere('position_rank', 'like', "%{$search}%");
            });
        }

        $status = (string) $request->input('status', 'all');
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query
            ->paginate($this->perPage($request))
            ->through(fn (SeamanLoanApplication $application) => $this->format($application));

        return $this->paginatedResponse($paginator);
    }

    public function update(Request $request, SeamanLoanApplication $seamanLoanApplication): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', SeamanLoanApplication::STATUSES)],
        ]);

        $seamanLoanApplication->update($data);

        return $this->success(
            $this->format($seamanLoanApplication->fresh(['payments.recorder'])),
            'Loan inquiry updated.'
        );
    }

    public function storePayment(Request $request, SeamanLoanApplication $seamanLoanApplication): JsonResponse
    {
        $data = $this->validatedPayment($request);
        $remainingError = $this->paymentAmountError($seamanLoanApplication, (float) $data['amount']);

        if ($remainingError) {
            return $remainingError;
        }

        $seamanLoanApplication->payments()->create([
            ...$data,
            'recorded_by' => $request->user()?->id,
        ]);

        return $this->success(
            $this->format($seamanLoanApplication->fresh(['payments.recorder'])),
            'Payment recorded.',
            201
        );
    }

    public function updatePayment(
        Request $request,
        SeamanLoanApplication $seamanLoanApplication,
        SeamanLoanPayment $seamanLoanPayment
    ): JsonResponse {
        if ($seamanLoanPayment->seaman_loan_application_id !== $seamanLoanApplication->id) {
            return $this->error('Payment not found.', 404);
        }

        $data = $this->validatedPayment($request);
        $remainingError = $this->paymentAmountError($seamanLoanApplication, (float) $data['amount'], $seamanLoanPayment->id);

        if ($remainingError) {
            return $remainingError;
        }

        $seamanLoanPayment->update($data);

        return $this->success(
            $this->format($seamanLoanApplication->fresh(['payments.recorder'])),
            'Payment updated.'
        );
    }

    public function destroyPayment(
        SeamanLoanApplication $seamanLoanApplication,
        SeamanLoanPayment $seamanLoanPayment
    ): JsonResponse {
        if ($seamanLoanPayment->seaman_loan_application_id !== $seamanLoanApplication->id) {
            return $this->error('Payment not found.', 404);
        }

        $seamanLoanPayment->delete();

        return $this->success(
            $this->format($seamanLoanApplication->fresh(['payments.recorder'])),
            'Payment removed.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:500'],
            'birthday' => ['required', 'date'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:191'],
            'position_rank' => ['required', 'string', 'max:191'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'departure_date' => ['required', 'date'],
            'agency_name' => ['required', 'string', 'max:191'],
            'loanable_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $application = SeamanLoanApplication::create([
            ...$data,
            'status' => SeamanLoanApplication::STATUS_PENDING,
        ]);

        return $this->success([
            'id' => $application->id,
        ], 'Your seaman loan inquiry has been submitted. We will contact you shortly.', 201);
    }

    private function validatedPayment(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:'.implode(',', SeamanLoanPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function paymentAmountError(
        SeamanLoanApplication $application,
        float $amount,
        ?int $ignorePaymentId = null
    ): ?JsonResponse {
        $query = $application->payments();

        if ($ignorePaymentId) {
            $query->where('id', '!=', $ignorePaymentId);
        }

        $totalPaid = (float) $query->sum('amount');
        $remaining = round(max(0, (float) $application->loanable_amount - $totalPaid), 2);

        if ($amount - $remaining > 0.009) {
            return $this->error('Payment exceeds remaining balance.', 422, [
                'amount' => ['Payment cannot exceed the remaining balance of '.$remaining.'.'],
            ]);
        }

        return null;
    }

    private function format(SeamanLoanApplication $application): array
    {
        $payments = $application->relationLoaded('payments')
            ? $application->payments
            : $application->payments()->with('recorder')->get();

        $totalPaid = round((float) $payments->sum('amount'), 2);
        $loanable = round((float) $application->loanable_amount, 2);
        $remaining = round(max(0, $loanable - $totalPaid), 2);

        return [
            'id' => $application->id,
            'full_name' => $application->full_name,
            'address' => $application->address,
            'birthday' => $application->birthday?->toDateString(),
            'phone' => $application->phone,
            'email' => $application->email,
            'position_rank' => $application->position_rank,
            'basic_salary' => $application->basic_salary,
            'departure_date' => $application->departure_date?->toDateString(),
            'agency_name' => $application->agency_name,
            'loanable_amount' => $application->loanable_amount,
            'total_paid' => number_format($totalPaid, 2, '.', ''),
            'remaining_balance' => number_format($remaining, 2, '.', ''),
            'status' => $application->status,
            'payments' => $payments->map(fn (SeamanLoanPayment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'method_label' => SeamanLoanPayment::METHOD_LABELS[$payment->method] ?? $payment->method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toDateString(),
                'note' => $payment->note,
                'recorded_by_name' => $payment->recorder?->name,
                'created_at' => $payment->created_at?->timezone('Asia/Manila')->toIso8601String(),
            ])->values(),
            'created_at' => $application->created_at?->timezone('Asia/Manila')->toIso8601String(),
            'updated_at' => $application->updated_at?->timezone('Asia/Manila')->toIso8601String(),
        ];
    }
}
