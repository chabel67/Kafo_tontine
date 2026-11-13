<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Application\RotationCashOutService;
use App\Modules\Tontine\Application\RotationClosureService;
use App\Modules\Tontine\Application\RotationDepartureService;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Http\Resources\CampaignResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationDeparture;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Tontines rotatives — lot 8 : départs (D-R7), remplaçants, sorties de caisse en deux temps
 * (remboursements, reliquat), clôture financière (D-R11).
 */
class AdminRotationLifecycleController extends Controller
{
    public function __construct(
        private readonly RotationDepartureService $departures,
        private readonly RotationCashOutService   $cashOut,
        private readonly RotationClosureService   $closure,
        private readonly AuditService             $audit,
    ) {}

    /** GET /admin/campaigns/{campaign}/rotation/departures */
    public function departures(Campaign $campaign): JsonResponse
    {
        $rows = RotationDeparture::with(['membership.user:id,full_name', 'replacement.user:id,full_name', 'attempts'])
            ->where('campaign_id', $campaign->id)->orderByDesc('created_at')->get()
            ->map(fn (RotationDeparture $d) => [
                'id' => $d->id, 'membership_id' => $d->membership_id, 'full_name' => $d->membership?->user?->full_name,
                'refund_minor' => $d->refund_minor, 'reason' => $d->reason, 'status' => $d->status,
                'decided_by' => $d->decided_by, 'decided_at' => $d->decided_at?->toIso8601String(),
                'replacement' => $d->replacement ? ['membership_id' => $d->replacement->id, 'full_name' => $d->replacement->user?->full_name] : null,
                'attempts' => $d->attempts->map(fn ($a) => $this->attempt($a))->values(),
            ]);

        return ApiResponse::success([
            'departures'      => $rows,
            'surplus_payouts' => RotationPayoutAttempt::with('membership.user:id,full_name')->where('campaign_id', $campaign->id)
                ->where('kind', RotationPayoutAttempt::KIND_SURPLUS)->orderByDesc('created_at')->get()
                ->map(fn ($a) => [...$this->attempt($a), 'full_name' => $a->membership?->user?->full_name])->values(),
        ]);
    }

    /** POST /admin/memberships/{membership}/rotation/departure — { refund_minor, reason } */
    public function startDeparture(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate([
            'refund_minor' => ['required', 'integer', 'min:0'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $d = $this->departures->start($membership, (int) $data['refund_minor'], $data['reason'], $request->user());

        $this->audit->logFromRequest($request, 'rotation.departure.start', 'membership', $membership->id, ['status' => $membership->status->value],
            ['departure_id' => $d->id, 'refund_minor' => $d->refund_minor, 'reason' => $d->reason]);

        return ApiResponse::created(['id' => $d->id, 'status' => $d->status, 'refund_minor' => $d->refund_minor]);
    }

    /** POST /admin/rotation-departures/{departure}/replacement — { user_id } */
    public function replace(Request $request, RotationDeparture $departure): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id']]);
        $m    = $this->departures->replace($departure, User::findOrFail($data['user_id']), $request->user());

        $this->audit->logFromRequest($request, 'rotation.departure.replacement', 'membership', $m->id, [],
            ['departure_id' => $departure->id, 'replaces_membership_id' => $departure->membership_id, 'user_id' => $m->user_id]);

        return ApiResponse::created(['membership_id' => $m->id, 'user_id' => $m->user_id]);
    }

    /** POST /admin/rotation-departures/{departure}/refund/initiate — step-up, versant ≠ décideur. */
    public function initiateRefund(Request $request, RotationDeparture $departure): JsonResponse
    {
        $data = $this->payoutPayload($request);
        $a    = $this->cashOut->initiateDepartureRefund($departure, $data['channel'], $data['phone'] ?? null, $data['idempotency_key'], $request->user());
        $this->audit->logFromRequest($request, 'rotation.cash_out.initiate', 'rotation_payout_attempt', $a->id, [], $this->attempt($a));

        return ApiResponse::success($this->attempt($a));
    }

    /** POST /admin/campaigns/{campaign}/rotation/surplus/payouts/initiate */
    public function initiateSurplus(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $this->payoutPayload($request, [
            'membership_id' => ['required', 'uuid'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'reason'        => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $a = $this->cashOut->initiateSurplus($campaign, Membership::findOrFail($data['membership_id']), (int) $data['amount_minor'],
            $data['reason'], $data['channel'], $data['phone'] ?? null, $data['idempotency_key'], $request->user());
        $this->audit->logFromRequest($request, 'rotation.cash_out.initiate', 'rotation_payout_attempt', $a->id, [], $this->attempt($a));

        return ApiResponse::success($this->attempt($a));
    }

    /** POST /admin/rotation-cash-outs/{attempt}/confirm */
    public function confirmCashOut(Request $request, RotationPayoutAttempt $attempt): JsonResponse
    {
        $data = $request->validate(['external_reference' => ['nullable', 'string', 'max:100']]);
        $a    = $this->cashOut->confirm($attempt, $data['external_reference'] ?? null, $request->user());
        $this->audit->logFromRequest($request, 'rotation.cash_out.confirm', 'rotation_payout_attempt', $a->id, ['status' => 'processing'], $this->attempt($a));

        return ApiResponse::success($this->attempt($a));
    }

    /** POST /admin/rotation-cash-outs/{attempt}/fail */
    public function failCashOut(Request $request, RotationPayoutAttempt $attempt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $a    = $this->cashOut->fail($attempt, $data['reason'], $request->user());
        $this->audit->logFromRequest($request, 'rotation.cash_out.fail', 'rotation_payout_attempt', $a->id, ['status' => 'processing'], $this->attempt($a));

        return ApiResponse::success($this->attempt($a));
    }

    /** GET /admin/campaigns/{campaign}/rotation/closure — conditions de clôture, reliquat, obligations. */
    public function closurePreview(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success($this->closure->preview($campaign));
    }

    /** POST /admin/campaigns/{campaign}/rotation/surplus/revenue — { amount_minor, reason } (step-up). */
    public function surplusToRevenue(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $c = $this->closure->surplusToRevenue($campaign, (int) $data['amount_minor'], $data['reason'], $request->user());
        $this->audit->logFromRequest($request, 'rotation.surplus.revenue', 'tontine_campaign', $c->id, [],
            ['amount_minor' => (int) $data['amount_minor'], 'reason' => $data['reason']]);

        return ApiResponse::success($this->closure->preview($c));
    }

    /** POST /admin/campaigns/{campaign}/rotation/close — { reason } (step-up). */
    public function close(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $c    = $this->closure->close($campaign, $request->user());
        $this->audit->logFromRequest($request, 'campaign.rotation.closed', 'tontine_campaign', $c->id, ['status' => 'active'],
            ['status' => 'closed', 'reason' => $data['reason']]);

        return ApiResponse::success(new CampaignResource($c));
    }

    private function payoutPayload(Request $request, array $extra = []): array
    {
        return $request->validate([
            'channel'         => ['required', 'in:cash,mtn,moov,orange,wave'],
            'phone'           => ['required_unless:channel,cash', 'nullable', 'string', 'regex:/^\+\d{7,15}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            ...$extra,
        ]);
    }

    private function attempt(RotationPayoutAttempt $a): array
    {
        return [
            'id' => $a->id, 'kind' => $a->kind, 'membership_id' => $a->membership_id, 'attempt_no' => $a->attempt_no,
            'amount_minor' => $a->amount_minor, 'reason' => $a->reason, 'reference' => $a->reference,
            'channel' => $a->channel, 'status' => $a->status, 'failure_reason' => $a->failure_reason,
            'initiated_at' => $a->initiated_at?->toIso8601String(), 'completed_at' => $a->completed_at?->toIso8601String(),
        ];
    }
}
