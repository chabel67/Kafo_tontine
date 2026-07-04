<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly AuditService   $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with(['membership.user', 'membership.campaign'])
            ->when($request->query('status'),      fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('channel'),     fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('membership_id'), fn ($q, $m) => $q->where('membership_id', $m))
            ->when($request->query('search'), fn ($q, $s) =>
                $q->whereHas('membership.user', fn ($u) =>
                    $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                )
            )
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            PaymentResource::collection($payments)->response()->getData(true)['data'],
            200,
            [
                'total'        => $payments->total(),
                'per_page'     => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
            ],
        );
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['membership.user', 'membership.campaign', 'installmentPayments.installment']);

        return ApiResponse::success(new PaymentResource($payment));
    }

    public function recordCash(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid', 'exists:memberships,id'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'notes'         => ['nullable', 'string', 'max:500'],
            'force'         => ['nullable', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);

        $membership = Membership::findOrFail($data['membership_id']);
        $payment    = $this->service->recordCash(
            $membership,
            (int) $data['amount_minor'],
            $request->user()->id,
            $data['notes'] ?? null,
            $force,
        );

        // R-PAY-09 : trace le bypass du guard de double encaissement.
        if ($force) {
            $this->audit->logFromRequest(
                $request,
                'payment.cash.duplicate_bypass',
                'payment',
                $payment->id,
                [],
                [
                    'new_payment_id' => $payment->id,
                    'membership_id'  => $payment->membership_id,
                    'amount_minor'   => $payment->amount_minor,
                ],
            );
        }

        return ApiResponse::created(new PaymentResource($payment->load(['membership.user', 'membership.campaign'])));
    }

    public function confirm(Payment $payment, Request $request): JsonResponse
    {
        try {
            $confirmed = $this->service->confirmManual($payment, $request->user()->id);
            return ApiResponse::success(new PaymentResource(
                $confirmed->load(['membership.user', 'membership.campaign', 'installmentPayments.installment'])
            ));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }
    }

    public function cancel(Payment $payment): JsonResponse
    {
        try {
            $cancelled = $this->service->cancelPayment($payment);
            return ApiResponse::success(new PaymentResource(
                $cancelled->load(['membership.user', 'membership.campaign'])
            ));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }
    }

    public function initiateMomo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid', 'exists:memberships,id'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'channel'       => ['required', 'string', Rule::in(['mtn', 'orange', 'moov', 'wave'])],
            'phone'         => ['required', 'string', 'regex:/^\+\d{7,15}$/'],
        ]);

        $membership = Membership::findOrFail($data['membership_id']);
        $payment    = $this->service->initiateMomo(
            $membership,
            (int) $data['amount_minor'],
            PaymentChannel::from($data['channel']),
            $data['phone'],
            $request->user()->id,
        );

        return ApiResponse::created(new PaymentResource($payment->load(['membership.user', 'membership.campaign'])));
    }
}
