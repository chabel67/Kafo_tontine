<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Application\EarlyWithdrawalService;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Http\Requests\ConfirmWithdrawalPayoutRequest;
use App\Modules\Tontine\Http\Requests\CreateEarlyWithdrawalRequest;
use App\Modules\Tontine\Http\Requests\InitiateWithdrawalPayoutRequest;
use App\Modules\Tontine\Http\Requests\ReasonRequest;
use App\Modules\Tontine\Http\Resources\EarlyWithdrawalResource;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Retraits anticipés — back-office (R-WDR-*).
 * Instruction : withdrawal.review ; versement : withdrawal.disburse (+ step-up au lancement).
 */
class AdminEarlyWithdrawalController extends Controller
{
    private const RELATIONS = ['user', 'membership.campaign', 'requestedBy', 'assignedTo', 'decidedBy', 'disbursedBy'];

    public function __construct(
        private readonly EarlyWithdrawalService $withdrawals,
        private readonly AuditService           $audit,
        private readonly LedgerService          $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'      => ['nullable', 'in:awaiting,' . implode(',', array_column(EarlyWithdrawalStatus::cases(), 'value'))],
            'campaign_id' => ['nullable', 'uuid'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $page = EarlyWithdrawal::with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $s) => $s === 'awaiting'
                ? $q->whereIn('status', EarlyWithdrawalStatus::awaitingDecision())
                : $q->where('status', $s))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->whereHas('membership', fn ($m) => $m->where('campaign_id', $id)))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'ilike', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%"))))
            // File d'instruction : les plus anciennes demandes non traitées d'abord (FIFO)
            ->orderByRaw("CASE WHEN status IN ('pending','under_review','info_requested') THEN 0
                               WHEN status IN ('approved','payout_processing','payout_failed') THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->paginate(15);

        $items = collect($page->items());

        // Disponible estimé par ligne, calculé par lot (pas de N+1)
        $membershipIds = $items->pluck('membership_id')->unique()->values()->all();
        $balances      = $this->ledger->balances(array_map(fn ($id) => "MEMBER_SAVINGS:{$id}", $membershipIds));
        $committed     = EarlyWithdrawal::whereIn('membership_id', $membershipIds)
            ->whereIn('status', EarlyWithdrawalStatus::committed())
            ->selectRaw('membership_id, SUM(amount_minor) as total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $rows = $items->map(function (EarlyWithdrawal $w) use ($balances, $committed) {
            $own = in_array($w->status->value, EarlyWithdrawalStatus::committed(), true) ? $w->amount_minor : 0;
            return [
                ...(new EarlyWithdrawalResource($w))->resolve(),
                'available_minor' => max(0, ($balances["MEMBER_SAVINGS:{$w->membership_id}"] ?? 0) - ((int) ($committed[$w->membership_id] ?? 0) - $own)),
            ];
        });

        return ApiResponse::success($rows, 200, [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return ApiResponse::success($this->withdrawals->stats($request->query('from'), $request->query('to')));
    }

    /** Dossier d'instruction : demande, calcul détaillé, contexte financier du membre, tentatives, décisions. */
    public function show(EarlyWithdrawal $withdrawal): JsonResponse
    {
        $withdrawal->load([...self::RELATIONS, 'attempts.initiatedBy', 'attempts.completedBy']);
        $membershipId = $withdrawal->membership_id;

        $loans = Loan::with('repayments')
            ->where('membership_id', $membershipId)
            ->orderByDesc('created_at')
            ->get();

        $history = $this->audit->forEntities(['early_withdrawal' => [$withdrawal->id]], 50);

        return ApiResponse::success([
            ...(new EarlyWithdrawalResource($withdrawal))->resolve(),
            'breakdown'      => $this->withdrawals->breakdown($withdrawal->membership, $withdrawal->id),
            'member_context' => [
                'contributions'     => Payment::where('membership_id', $membershipId)
                    ->where('status', 'confirmed')
                    ->orderByDesc('confirmed_at')
                    ->limit(10)
                    ->get(['id', 'reference', 'amount_minor', 'channel', 'confirmed_at'])
                    ->map(fn ($p) => [
                        'id'           => $p->id,
                        'reference'    => $p->reference,
                        'amount_minor' => $p->amount_minor,
                        'channel'      => $p->channel?->value,
                        'confirmed_at' => $p->confirmed_at?->toIso8601String(),
                    ]),
                'installments_late' => Installment::where('membership_id', $membershipId)->where('status', 'late')->count(),
                'installments_ever_late' => Installment::where('membership_id', $membershipId)->whereNotNull('late_marked_at')->count(),
                'previous_withdrawals' => EarlyWithdrawalResource::collection(
                    EarlyWithdrawal::where('membership_id', $membershipId)->where('id', '!=', $withdrawal->id)->orderByDesc('created_at')->get()
                ),
                'loans'             => $loans->map(fn (Loan $l) => [
                    'id'                => $l->id,
                    'reference'         => $l->reference,
                    'product_type'      => $l->product_type?->value,
                    'status'            => $l->status?->value,
                    'principal_minor'   => $l->principal_minor,
                    'outstanding_minor' => $l->outstanding_minor,
                    'repayments'        => $l->repayments->map(fn ($r) => [
                        'amount_minor' => $r->amount_minor,
                        'channel'      => $r->channel,
                        'created_at'   => $r->created_at?->toIso8601String(),
                    ]),
                ]),
            ],
            'decisions'      => collect($history->items())->map(fn ($log) => [
                'action'     => $log->action,
                'user_name'  => $log->user_name,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function withdrawable(Membership $membership): JsonResponse
    {
        return ApiResponse::success($this->withdrawals->breakdown($membership));
    }

    public function store(CreateEarlyWithdrawalRequest $request): JsonResponse
    {
        $data       = $request->validated();
        $membership = Membership::findOrFail($data['membership_id']);

        $withdrawal = $this->withdrawals->request(
            $membership, (int) $data['amount_minor'], $data['reason'] ?? null,
            $data['payout_channel'], $data['payout_phone'] ?? null,
            $request->user(), 'staff', $data['idempotency_key'],
        );

        if ($withdrawal->wasRecentlyCreated) {
            $this->log($request, 'request', $withdrawal, [], [
                'amount_minor' => $withdrawal->amount_minor, 'membership_id' => $membership->id, 'via' => 'staff',
            ]);
        }

        return ApiResponse::created(new EarlyWithdrawalResource($withdrawal->load(self::RELATIONS)));
    }

    public function review(Request $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $old = $withdrawal->status->value;
        $w   = $this->withdrawals->startReview($withdrawal, $request->user());
        $this->log($request, 'review', $w, ['status' => $old], ['status' => $w->status->value]);

        return $this->respond($w);
    }

    public function requestInfo(ReasonRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $old = $withdrawal->status->value;
        $w   = $this->withdrawals->requestInfo($withdrawal, $request->validated('reason'), $request->user());
        $this->log($request, 'request_info', $w, ['status' => $old], ['status' => $w->status->value, 'message' => $request->validated('reason')]);

        return $this->respond($w);
    }

    public function approve(Request $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $old = $withdrawal->status->value;
        $w   = $this->withdrawals->approve($withdrawal, $request->user());
        $this->log($request, 'approve', $w, ['status' => $old], ['status' => 'approved']);

        return $this->respond($w);
    }

    public function reject(ReasonRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $old = $withdrawal->status->value;
        $w   = $this->withdrawals->reject($withdrawal, $request->validated('reason'), $request->user());
        $this->log($request, 'reject', $w, ['status' => $old], ['status' => 'rejected', 'reason' => $request->validated('reason')]);

        return $this->respond($w);
    }

    public function cancel(Request $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $old = $withdrawal->status->value;
        $w   = $this->withdrawals->cancel($withdrawal);
        $this->log($request, 'cancel', $w, ['status' => $old], ['status' => 'cancelled']);

        return $this->respond($w);
    }

    public function initiatePayout(InitiateWithdrawalPayoutRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $data    = $request->validated();
        $attempt = $this->withdrawals->initiatePayout($withdrawal, $data['channel'], $data['phone'] ?? null, $data['idempotency_key'], $request->user());

        if ($attempt->wasRecentlyCreated) {
            $this->log($request, 'payout_initiate', $withdrawal, [], [
                'attempt_no' => $attempt->attempt_no, 'channel' => $attempt->channel, 'amount_minor' => $withdrawal->amount_minor,
            ]);
        }

        return $this->respond($withdrawal->fresh());
    }

    public function confirmPayout(ConfirmWithdrawalPayoutRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $w = $this->withdrawals->confirmPayout($withdrawal, $request->validated('external_reference'), $request->user());
        $this->log($request, 'payout_confirm', $w, ['status' => 'payout_processing'], [
            'status' => 'disbursed', 'amount_minor' => $w->amount_minor, 'ledger_transaction_id' => $w->ledger_transaction_id,
        ]);

        return $this->respond($w);
    }

    public function failPayout(ReasonRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $w = $this->withdrawals->failPayout($withdrawal, $request->validated('reason'), $request->user());
        $this->log($request, 'payout_fail', $w, ['status' => 'payout_processing'], ['status' => 'payout_failed', 'reason' => $request->validated('reason')]);

        return $this->respond($w);
    }

    private function respond(EarlyWithdrawal $w): JsonResponse
    {
        return ApiResponse::success(new EarlyWithdrawalResource($w->load([...self::RELATIONS, 'attempts.initiatedBy', 'attempts.completedBy'])));
    }

    private function log(Request $request, string $action, EarlyWithdrawal $w, array $old, array $new): void
    {
        $this->audit->logFromRequest($request, "early_withdrawal.{$action}", 'early_withdrawal', $w->id, $old, $new);
    }
}
