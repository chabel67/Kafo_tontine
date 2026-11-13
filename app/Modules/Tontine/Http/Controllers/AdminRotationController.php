<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Tontine\Application\RotationAssignmentService;
use App\Modules\Tontine\Application\RotationScheduleService;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Http\Resources\CampaignResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Tontines rotatives — back-office (lots 2 et suivants).
 * Lot 2 : aperçu du calendrier (dates, tours, cagnotte, incohérences) et confirmation.
 */
class AdminRotationController extends Controller
{
    public function __construct(
        private readonly RotationScheduleService   $schedule,
        private readonly RotationAssignmentService $assignment,
        private readonly AuditService              $audit,
    ) {}

    /** GET /admin/campaigns/{campaign}/rotation/rounds — tours, bénéficiaires, statuts. */
    public function rounds(Campaign $campaign): JsonResponse
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        $rounds = $campaign->rounds()->with('beneficiary.user:id,full_name,phone')->get()->map(fn ($r) => [
            'id'             => $r->id,
            'round_number'   => $r->round_number,
            'first_sequence' => $r->first_sequence,
            'last_sequence'  => $r->last_sequence,
            'scheduled_date' => $r->scheduled_date->toDateString(),
            'status'         => $r->status->value,
            'status_label'   => $r->status->label(),
            'expected_minor' => $r->expected_minor,
            'fee_minor'      => $r->fee_minor,
            'net_minor'      => $r->netMinor(),
            'distributed_minor' => $r->distributed_minor,
            'reference'      => $r->reference,
            'completed_at'   => $r->completed_at?->toIso8601String(),
            'beneficiary'    => $r->beneficiary ? [
                'membership_id' => $r->beneficiary->id,
                'user_id'       => $r->beneficiary->user_id,
                'full_name'     => $r->beneficiary->user?->full_name,
                'phone'         => $r->beneficiary->user?->phone,
            ] : null,
        ]);

        return ApiResponse::success([
            'rounds'       => $rounds,
            'order_locked' => (bool) $campaign->started_at,
        ]);
    }

    /** GET /admin/campaigns/{campaign}/rotation/overview — vue d'ensemble, tours, membres (lot 4). */
    public function overview(Campaign $campaign): JsonResponse
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        return ApiResponse::success(app(\App\Modules\Tontine\Application\RotationTrackingService::class)->overview($campaign));
    }

    /** GET /admin/rotation-rounds/{round}/coverage — couverture détaillée d'un tour. */
    public function coverage(\App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        return ApiResponse::success(app(\App\Modules\Tontine\Application\RotationTrackingService::class)->coverage($round));
    }

    /** GET /admin/memberships/{membership}/rotation — suivi d'un membre. */
    public function membership(\App\Modules\Tontine\Infrastructure\Models\Membership $membership): JsonResponse
    {
        if (! $membership->campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        return ApiResponse::success(app(\App\Modules\Tontine\Application\RotationTrackingService::class)->memberSummary($membership->load('user')));
    }

    /** POST /admin/installments/{installment}/penalty — pénalité au cas par cas (D-R10). */
    public function applyPenalty(Request $request, \App\Modules\Tontine\Infrastructure\Models\Installment $installment): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $old = (int) $installment->penalty_minor;
        $i   = app(\App\Modules\Tontine\Application\RotationTrackingService::class)
            ->applyPenalty($installment, (int) $data['amount_minor'], $data['reason'], $request->user());

        $this->audit->logFromRequest($request, 'installment.penalty.apply', 'installment', $i->id,
            ['penalty_minor' => $old], ['penalty_minor' => $i->penalty_minor, 'reason' => $data['reason'], 'membership_id' => $i->membership_id]);

        return ApiResponse::success(new \App\Modules\Tontine\Http\Resources\InstallmentResource($i));
    }

    /** POST /admin/installments/{installment}/penalty/cancel — annulation tant qu'elle n'est pas payée. */
    public function cancelPenalty(Request $request, \App\Modules\Tontine\Infrastructure\Models\Installment $installment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $old  = (int) $installment->penalty_minor;
        $i    = app(\App\Modules\Tontine\Application\RotationTrackingService::class)->cancelPenalty($installment, $request->user());

        $this->audit->logFromRequest($request, 'installment.penalty.cancel', 'installment', $i->id,
            ['penalty_minor' => $old], ['penalty_minor' => 0, 'reason' => $data['reason']]);

        return ApiResponse::success(new \App\Modules\Tontine\Http\Resources\InstallmentResource($i));
    }

    /** GET /admin/campaigns/{campaign}/rotation/reconciliation — rapprochement de la caisse (lot 6). */
    public function reconciliation(Campaign $campaign): JsonResponse
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        return ApiResponse::success(app(\App\Modules\Tontine\Application\RotationReconciliationService::class)->reconcile($campaign));
    }

    // ── Lot 5 : distributions (D-R3, D-R4, D-R9) ─────────────────────────

    /** GET /admin/rotation-rounds/{round}/payout — aperçu de la distribution. */
    public function payoutPreview(\App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        return ApiResponse::success($this->payouts()->preview($round));
    }

    /** POST /admin/rotation-rounds/{round}/approve — validation (motifs si impayés / retards). */
    public function approveRound(Request $request, \App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        $data = $request->validate([
            'unpaid_reason'              => ['nullable', 'string', 'max:500'],
            'beneficiary_arrears_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $r = $this->payouts()->approve($round, $request->user(), $data['unpaid_reason'] ?? null, $data['beneficiary_arrears_reason'] ?? null);

        $this->audit->logFromRequest($request, 'rotation.round.approve', 'rotation_round', $r->id, [], array_filter([
            'round_number'          => $r->round_number,
            'net_minor'             => $r->netMinor(),
            'unpaid_override'       => $r->override_reasons['unpaid'] ?? null,
            'beneficiary_arrears_override' => $r->override_reasons['beneficiary_arrears'] ?? null,
        ]));

        return ApiResponse::success($this->payouts()->preview($r));
    }

    /** POST /admin/rotation-rounds/{round}/payout/initiate — step-up, versant ≠ validateur. */
    public function initiatePayout(Request $request, \App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        $data = $request->validate([
            'channel'         => ['required', 'in:cash,mtn,moov,orange,wave'],
            'phone'           => ['required_unless:channel,cash', 'nullable', 'string', 'regex:/^\+\d{7,15}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        $attempt = $this->payouts()->initiate($round, $data['channel'], $data['phone'] ?? null, $data['idempotency_key'], $request->user());

        $this->audit->logFromRequest($request, 'rotation.round.payout_initiate', 'rotation_round', $round->id, [],
            ['attempt_id' => $attempt->id, 'attempt_no' => $attempt->attempt_no, 'channel' => $attempt->channel, 'amount_minor' => $attempt->amount_minor]);

        return ApiResponse::success($this->payouts()->preview($round->fresh()));
    }

    /** POST /admin/rotation-rounds/{round}/payout/confirm — écriture unique au grand livre. */
    public function confirmPayout(Request $request, \App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:100']]);
        $r    = $this->payouts()->confirm($round, $data['external_reference'] ?? null, $request->user());

        $this->audit->logFromRequest($request, 'rotation.round.payout_confirm', 'rotation_round', $r->id, ['status' => 'processing'],
            ['status' => 'completed', 'reference' => $r->reference, 'distributed_minor' => $r->distributed_minor, 'ledger_transaction_id' => $r->ledger_transaction_id]);

        return ApiResponse::success($this->payouts()->preview($r));
    }

    /** POST /admin/rotation-rounds/{round}/payout/fail — aucune écriture, nouvelle tentative possible. */
    public function failPayout(Request $request, \App\Modules\Tontine\Infrastructure\Models\RotationRound $round): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $r    = $this->payouts()->fail($round, $data['reason'], $request->user());

        $this->audit->logFromRequest($request, 'rotation.round.payout_fail', 'rotation_round', $r->id, ['status' => 'processing'],
            ['status' => 'failed', 'reason' => $data['reason']]);

        return ApiResponse::success($this->payouts()->preview($r));
    }

    private function payouts(): \App\Modules\Tontine\Application\RotationPayoutService
    {
        return app(\App\Modules\Tontine\Application\RotationPayoutService::class);
    }

    /** POST /admin/campaigns/{campaign}/rotation/draw — proposition de tirage (rien n'est écrit). */
    public function draw(Campaign $campaign): JsonResponse
    {
        $proposal = $this->assignment->draw($campaign);
        $names    = \App\Modules\Tontine\Infrastructure\Models\Membership::with('user:id,full_name')
            ->whereIn('id', $proposal['order'])->get()->keyBy('id');

        return ApiResponse::success([
            'seed'  => $proposal['seed'],
            'order' => array_map(fn ($id, $i) => [
                'round_number'  => $i + 1,
                'membership_id' => $id,
                'full_name'     => $names[$id]?->user?->full_name,
            ], $proposal['order'], array_keys($proposal['order'])),
        ]);
    }

    /** POST /admin/campaigns/{campaign}/rotation/assign — fige l'ordre (manuel ou tirage confirmé). */
    public function assign(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'method'   => ['required', 'in:manual,draw'],
            'order'    => ['required', 'array', 'min:2'],
            'order.*'  => ['uuid'],
            'seed'     => ['required_if:method,draw', 'nullable', 'integer'],
        ]);

        $result = $this->assignment->assign($campaign, $data['order'], $data['method'], $data['seed'] ?? null);

        $this->audit->logFromRequest($request, 'campaign.rotation.rounds_assigned', 'tontine_campaign', $campaign->id, [], $result);

        return $this->rounds($campaign->fresh());
    }

    /** POST /admin/campaigns/{campaign}/rotation/start — groupe complet, tours attribués → active. */
    public function start(Request $request, Campaign $campaign): JsonResponse
    {
        $started = $this->assignment->start($campaign, $request->user());

        $this->audit->logFromRequest($request, 'campaign.rotation.started', 'tontine_campaign', $campaign->id,
            ['status' => 'open'], ['status' => 'active', 'participants' => $started->max_members]);

        return ApiResponse::success(new CampaignResource($started));
    }

    /** GET /admin/campaigns/{campaign}/rotation/schedule — aperçu, ou calendrier confirmé. */
    public function schedulePreview(Campaign $campaign): JsonResponse
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        return ApiResponse::success($this->schedule->preview($campaign));
    }

    /** POST /admin/campaigns/{campaign}/rotation/schedule/confirm — fige dates et tours (brouillon). */
    public function confirmSchedule(Request $request, Campaign $campaign): JsonResponse
    {
        $campaign = $this->schedule->confirm($campaign);
        $preview  = $this->schedule->preview($campaign);

        $this->audit->logFromRequest($request, 'campaign.rotation.schedule_confirmed', 'tontine_campaign', $campaign->id, [], [
            'rounds'              => $preview['rounds_count'],
            'installments'        => $preview['installments_count'],
            'pot_minor'           => $preview['pot_minor'],
            'first_date'          => $preview['first_date'],
            'last_date'           => $preview['last_date'],
            'fee_mode'            => $preview['fee_mode'],
        ]);

        return ApiResponse::success(['campaign' => new CampaignResource($campaign), 'schedule' => $preview]);
    }
}
