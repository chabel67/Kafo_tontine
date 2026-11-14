<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Shared\BusinessTime;
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

        $page = $this->filtered($request)
            ->with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $s) => $s === 'awaiting'
                ? $q->whereIn('status', EarlyWithdrawalStatus::awaitingDecision())
                : $q->where('status', $s))
            // File d'instruction : les plus anciennes demandes non traitées d'abord (FIFO)
            ->orderByRaw("CASE WHEN status IN ('pending','under_review','info_requested') THEN 0
                               WHEN status IN ('approved','payout_processing','payout_failed') THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->paginate(15);

        $items = collect($page->items());

        // Disponible estimé par ligne (R-SAV-01, calcul unique), par lot (pas de N+1).
        // Pour une demande encore engagée, son propre montant n'est pas déduit d'elle-même.
        $available = app(\App\Modules\Tontine\Application\SavingsAvailabilityService::class)
            ->availableMany($items->pluck('membership_id')->all());

        $rows = $items->map(function (EarlyWithdrawal $w) use ($available) {
            $own = in_array($w->status->value, EarlyWithdrawalStatus::committed(), true) ? $w->amount_minor : 0;
            return [
                ...(new EarlyWithdrawalResource($w))->resolve(),
                'available_minor' => (int) ($available[$w->membership_id] ?? 0) + $own,
            ];
        });

        return ApiResponse::success($rows, 200, [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ]);
    }

    /**
     * GET /admin/early-withdrawals/counts — nombre de retraits par statut (onglets de la file),
     * mêmes filtres que la liste hors `status` (user_id, campaign_id, from, to, search).
     * Réponse : { by_status: { pending: n, … tous les statuts, 0 inclus }, awaiting: n
     * (pending + under_review + info_requested, comme le filtre `status=awaiting`), total }.
     */
    public function counts(Request $request): JsonResponse
    {
        $request->validate([
            'campaign_id' => ['nullable', 'uuid'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $rows = $this->filtered($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byStatus = [];
        foreach (EarlyWithdrawalStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        $awaiting = array_sum(array_map(fn ($s) => $byStatus[$s] ?? 0, EarlyWithdrawalStatus::awaitingDecision()));

        return ApiResponse::success([
            'by_status' => $byStatus,
            'awaiting'  => $awaiting,
            'total'     => array_sum($byStatus),
        ]);
    }

    /** Filtres communs à la file et à ses compteurs (hors statut). */
    private function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return EarlyWithdrawal::query()
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->whereHas('membership', fn ($m) => $m->where('campaign_id', $id)))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', BusinessTime::dayBoundsUtc($d)[0]))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', BusinessTime::dayBoundsUtc($d)[1]))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'ilike', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%"))));
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

        // Avances de l'adhésion + prêts communautaires du membre (R-LOAN-16, affichés non déduits).
        $loans = Loan::with('repayments')
            ->where(fn ($q) => $q->where('membership_id', $membershipId)
                ->orWhere(fn ($s) => $s->where('user_id', $withdrawal->user_id)->where('product_type', 'standard')))
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
            $data['payout_network'] ?? null,
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
        $attempt = $this->withdrawals->initiatePayout($withdrawal, $data['channel'], $data['phone'] ?? null, $data['idempotency_key'], $request->user(), $data['network'] ?? null);

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
