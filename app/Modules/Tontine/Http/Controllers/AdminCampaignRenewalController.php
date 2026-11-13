<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Tontine\Application\CampaignRenewalService;
use App\Modules\Tontine\Http\Resources\CampaignResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Reconduction des campagnes (R1–R4) : aperçu, confirmation, historique des cycles. */
class AdminCampaignRenewalController extends Controller
{
    public function __construct(
        private readonly CampaignRenewalService $renewals,
        private readonly AuditService           $audit,
    ) {}

    /** GET /admin/campaigns/{campaign}/renewal/candidates — membres de l'ancien cycle avec leur dette. */
    public function candidates(Campaign $campaign): JsonResponse
    {
        $rows = $this->renewals->candidates($campaign)->map(function ($m) {
            $debt = $this->renewals->debt($m);

            return [
                'membership_id' => $m->id, 'user_id' => $m->user_id, 'full_name' => $m->user?->full_name, 'phone' => $m->user?->phone,
                'status' => $m->status->value, 'committed_amount_minor' => $m->committed_amount_minor,
                'debt_minor' => $debt['minor'], 'debt_details' => $debt['details'],
                // R4 : un membre qui doit encore est décoché par défaut.
                'selected_by_default' => $debt['minor'] === 0,
            ];
        })->values();

        return ApiResponse::success(['candidates' => $rows]);
    }

    /** POST /admin/campaigns/{campaign}/renewal/preview — n'écrit rien. */
    public function preview(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::success($this->renewals->preview($campaign, $this->payload($request)));
    }

    /** POST /admin/campaigns/{campaign}/renewal — { ...payload, idempotency_key } */
    public function confirm(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);
        $payload = $this->payload($request);
        $wasRenewed = $campaign->nextCycle()->exists();

        $target = $this->renewals->confirm($campaign, $payload, $request->input('idempotency_key'), $request->user());

        if (! $wasRenewed) {
            $this->audit->logFromRequest($request, 'campaign.renewed', 'tontine_campaign', $campaign->id, [], [
                'target_campaign_id' => $target->id, 'cycle_number' => $target->cycle_number,
                'members' => count($payload['members'] ?? []),
                'debt_overrides' => collect($payload['members'] ?? [])->filter(fn ($m) => ! empty($m['debt_override_reason']))->values(),
            ]);
        }

        return ApiResponse::created(new CampaignResource($target->loadCount('memberships')));
    }

    /** GET /admin/campaigns/{campaign}/cycles — historique de la série avec indicateurs par cycle. */
    public function cycles(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(['series_id' => $campaign->series_id, 'cycles' => $this->renewals->cycles($campaign)]);
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'name'                      => ['sometimes', 'string', 'max:150'],
            'description'               => ['sometimes', 'nullable', 'string'],
            'first_installment_date'    => ['sometimes', 'nullable', 'date'],
            'contribution_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_contribution_minor'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_contribution_minor'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'grace_period_days'         => ['sometimes', 'integer', 'min:0', 'max:30'],
            'membership_fee_minor'      => ['sometimes', 'integer', 'min:0'],
            'allow_early_withdrawals'   => ['sometimes', 'boolean'],
            'duration_installments'     => ['sometimes', 'integer', 'min:1', 'max:500'],
            'market_calendar_id'        => ['sometimes', 'nullable', 'integer'],
            'installments_per_round'    => ['sometimes', 'integer', 'min:1', 'max:366'],
            'contribution_schedule'     => ['sometimes', 'array'],
            'rotation_fee_mode'         => ['sometimes', 'in:none,payout_deduction,contribution_surcharge'],
            'rotation_fee_minor'        => ['sometimes', 'integer', 'min:0'],
            'members'                   => ['present', 'array'],
            'members.*.membership_id'   => ['nullable', 'uuid'],
            'members.*.user_id'         => ['nullable', 'uuid'],
            'members.*.committed_amount_minor' => ['nullable', 'integer', 'min:1'],
            'members.*.debt_override_reason'   => ['nullable', 'string', 'max:500'],
        ]);
    }
}
