<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use App\Shared\BusinessTime;
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

    /**
     * GET /admin/payments — filtres : status, channel, membership_id, search.
     * Lot A-bis (additif) : `from` / `to` (AAAA-MM-JJ, jours métier en BusinessTime, bornes
     * incluses, sur `created_at`) et `mine=1` (paiements saisis par l'agent connecté).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $payments = Payment::with(['membership.user', 'membership.campaign'])
            ->when($request->query('status'),      fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('channel'),     fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('membership_id'), fn ($q, $m) => $q->where('membership_id', $m))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', BusinessTime::dayBoundsUtc($d)[0]))
            ->when($request->query('to'),   fn ($q, $d) => $q->where('created_at', '<=', BusinessTime::dayBoundsUtc($d)[1]))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $request->user()->id))
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

    /**
     * GET /admin/payments/:id — fiche paiement (EF-PAY-02).
     * Additif : `recorded_by` (agent ayant saisi le paiement, null = initié par le membre),
     * `ledger_transaction` (écriture d'encaissement + éventuelle contre-passation) et
     * `history` (journal d'audit du paiement, sans IP ni user-agent).
     */
    public function show(Payment $payment, Request $request): JsonResponse
    {
        $payment->load(['membership.user', 'membership.campaign', 'installmentPayments.installment']);

        // Un paiement initié depuis l'application porte created_by = le membre lui-même.
        $byStaff    = $payment->created_by && $payment->created_by !== $payment->membership?->user_id;
        $recordedBy = $byStaff ? User::select('id', 'full_name')->find($payment->created_by) : null;

        $txn = LedgerTransaction::query()
            ->whereNull('reverses_id')
            ->where('metadata->payment_id', $payment->id)
            ->orderBy('created_at')
            ->first();
        $reversal = $txn
            ? LedgerTransaction::select('id', 'reference', 'created_at')->where('reverses_id', $txn->id)->first()
            : null;

        $history = AuditLog::query()
            ->where('entity_type', 'payment')
            ->where('entity_id', $payment->id)
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'user_name'  => $log->user_name,
                'reason'     => $log->new_values['reason'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            ...(new PaymentResource($payment))->toArray($request),
            'recorded_by'        => $recordedBy ? ['id' => $recordedBy->id, 'full_name' => $recordedBy->full_name] : null,
            'ledger_transaction' => $txn ? [
                'id'          => $txn->id,
                'reference'   => $txn->reference,
                'description' => $txn->description,
                'created_at'  => $txn->created_at?->toIso8601String(),
                'reversed_by' => $reversal ? [
                    'id'         => $reversal->id,
                    'reference'  => $reversal->reference,
                    'created_at' => $reversal->created_at?->toIso8601String(),
                ] : null,
            ] : null,
            'history'            => $history,
        ]);
    }

    public function recordCash(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid', 'exists:memberships,id'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'notes'         => ['nullable', 'string', 'max:500'],
            'force'            => ['nullable', 'boolean'],
            'confirm_atypical' => ['nullable', 'boolean'],
            // R-FEE-02 : `membership_fee` = frais d'adhésion (jamais de l'épargne).
            'purpose'          => ['nullable', 'in:contribution,membership_fee'],
        ]);

        if (($data['purpose'] ?? 'contribution') === 'membership_fee') {
            $payment = $this->service->recordMembershipFee(
                Membership::findOrFail($data['membership_id']),
                (int) $data['amount_minor'],
                $request->user()->id,
                $data['notes'] ?? null,
            );

            $this->audit->logFromRequest($request, 'payment.membership_fee', 'payment', $payment->id, [], [
                'membership_id' => $payment->membership_id,
                'amount_minor'  => $payment->amount_minor,
            ]);

            return ApiResponse::created(new PaymentResource($payment->load(['membership.user', 'membership.campaign'])));
        }

        $force           = (bool) ($data['force'] ?? false);
        $confirmAtypical = (bool) ($data['confirm_atypical'] ?? false);

        $membership = Membership::findOrFail($data['membership_id']);
        $payment    = $this->service->recordCash(
            $membership,
            (int) $data['amount_minor'],
            $request->user()->id,
            $data['notes'] ?? null,
            $force,
            $confirmAtypical,
        );

        // R-PAY-05 : trace la confirmation explicite d'un montant atypique
        // (uniquement si le montant l'était réellement).
        if ($confirmAtypical && $this->service->isAtypicalAmount($membership, (int) $data['amount_minor'])) {
            $this->audit->logFromRequest(
                $request,
                'payment.cash.atypical_confirmed',
                'payment',
                $payment->id,
                [],
                [
                    'payment_id'            => $payment->id,
                    'membership_id'         => $payment->membership_id,
                    'amount_minor'          => $payment->amount_minor,
                    'expected_amount_minor' => (int) $membership->committed_amount_minor,
                ],
            );
        }

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
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $before    = $payment->status?->value;
            $confirmed = $this->service->confirmManual($payment, $request->user()->id);

            $this->audit->logFromRequest(
                $request,
                'payment.confirm_manual',
                'payment',
                $confirmed->id,
                ['status' => $before],
                [
                    'status'       => $confirmed->status?->value,
                    'amount_minor' => $confirmed->amount_minor,
                    'channel'      => $confirmed->channel?->value,
                    'reason'       => $data['reason'] ?? null,
                ],
            );

            return ApiResponse::success(new PaymentResource(
                $confirmed->load(['membership.user', 'membership.campaign', 'installmentPayments.installment'])
            ));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }
    }

    public function cancel(Payment $payment, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $before    = $payment->status?->value;
            $cancelled = $this->service->cancelPayment($payment);

            $this->audit->logFromRequest(
                $request,
                'payment.cancel',
                'payment',
                $cancelled->id,
                ['status' => $before],
                [
                    'status'       => $cancelled->status?->value,
                    'amount_minor' => $cancelled->amount_minor,
                    'channel'      => $cancelled->channel?->value,
                    'reason'       => $data['reason'] ?? null,
                ],
            );

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
