<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Tontine\Application\CampaignFinancialsService;
use App\Modules\Tontine\Application\CampaignService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Http\Requests\CreateCampaignRequest;
use App\Modules\Tontine\Http\Resources\CampaignResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class AdminCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $service,
        private readonly AuditService    $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::query()
            ->withCount('memberships')
            ->with('marketCalendar')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            CampaignResource::collection($campaigns)->response()->getData(true)['data'],
            200,
            [
                'total'        => $campaigns->total(),
                'per_page'     => $campaigns->perPage(),
                'current_page' => $campaigns->currentPage(),
                'last_page'    => $campaigns->lastPage(),
            ],
        );
    }

    public function store(CreateCampaignRequest $request): JsonResponse
    {
        $campaign = $this->service->create($request->validated(), $request->user());

        return ApiResponse::created(new CampaignResource($campaign));
    }

    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->load(['marketCalendar', 'creator']);
        $campaign->loadCount('memberships');
        $campaign->setAttribute('financials', app(CampaignFinancialsService::class)->forCampaign($campaign));

        return ApiResponse::success(new CampaignResource($campaign));
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $data     = $request->validate([
            'name'                      => ['sometimes', 'string', 'max:150'],
            'description'               => ['nullable', 'string'],
            'grace_period_days'         => ['sometimes', 'integer', 'min:0', 'max:30'],
            'market_calendar_id'        => ['nullable', 'integer', 'exists:market_calendars,id'],
            'first_installment_date'    => ['sometimes', 'date'],
            'duration_installments'     => ['sometimes', 'integer', 'min:1'],
            'contribution_amount_minor' => ['sometimes', 'integer', 'min:1'],
            'max_members'               => ['nullable', 'integer', 'min:1'],
            'membership_fee_minor'      => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'allow_early_withdrawals'   => ['sometimes', 'boolean'],
        ]);

        $old      = $campaign->only(array_keys($data));
        $campaign = $this->service->update($campaign, $data);

        $this->audit->logFromRequest($request, 'campaign.update', 'tontine_campaign', $campaign->id,
            $old, $campaign->only(array_keys($data)));

        return ApiResponse::success(new CampaignResource($campaign));
    }

    /**
     * PATCH /admin/campaigns/{id}/withdrawal-policy — R-WDR-08.
     * Autoriser / suspendre les retraits anticipés d'une campagne, avec motif tracé.
     * N'affecte que les NOUVELLES demandes : les demandes existantes suivent leur cycle.
     */
    public function updateWithdrawalPolicy(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'allow_early_withdrawals' => ['required', 'boolean'],
            'reason'                  => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $old      = (bool) $campaign->allow_early_withdrawals;
        $campaign = $this->service->updateWithdrawalPolicy($campaign, (bool) $data['allow_early_withdrawals']);

        $this->audit->logFromRequest($request, 'campaign.withdrawal_policy.update', 'tontine_campaign', $campaign->id,
            ['allow_early_withdrawals' => $old],
            ['allow_early_withdrawals' => $campaign->allow_early_withdrawals, 'reason' => $data['reason']]);

        return ApiResponse::success(new CampaignResource($campaign));
    }

    /**
     * PATCH /admin/campaigns/{id}/status
     *
     * R-CAMP-04 / R-AUTH-05 : chaque transition est auditée (`campaign.status_changed`)
     * avec son motif. Le motif est obligatoire (≥ 10 caractères) pour les
     * transitions irréversibles open → active et active → closed.
     */
    public function transition(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:draft,open,active,closed'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = CampaignStatus::from($data['status']);
        $reason    = trim((string) ($data['reason'] ?? ''));
        $oldStatus = $campaign->status;

        // R-CAMP-08 / R-AUTH-03 : la clôture a une seule porte d'entrée, POST /close
        // (step-up OTP obligatoire, aperçu des payouts, audit `campaign.close`).
        // Ce PATCH n'exigeait aucun step-up : il refuse désormais `closed`.
        if ($newStatus === CampaignStatus::Closed) {
            return ApiResponse::error(
                'campaign_close_endpoint_required',
                'La clôture d\'une campagne passe par l\'écran de clôture (aperçu des versements et code de confirmation).',
                422,
                ['details' => ['endpoint' => "/api/admin/campaigns/{$campaign->id}/close", 'method' => 'POST']],
            );
        }

        if (self::requiresReason($oldStatus, $newStatus) && mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['Le motif est obligatoire (10 caractères minimum) pour cette transition.'],
            ]);
        }

        $campaign = $this->service->transition($campaign, $newStatus, $request->user(), $reason);

        $this->audit->logFromRequest(
            $request,
            'campaign.status_changed',
            'tontine_campaign',
            $campaign->id,
            ['status' => $oldStatus?->value],
            [
                'status' => $campaign->status?->value,
                'reason' => $reason !== '' ? $reason : null,
            ],
        );

        return ApiResponse::success(new CampaignResource($campaign));
    }

    /** Transitions irréversibles exigeant un motif (R-CAMP-04). */
    private static function requiresReason(?CampaignStatus $from, CampaignStatus $to): bool
    {
        return ($from === CampaignStatus::Open && $to === CampaignStatus::Active)
            || ($from === CampaignStatus::Active && $to === CampaignStatus::Closed);
    }

    public function installmentPreview(Campaign $campaign): JsonResponse
    {
        $generator = app(\App\Modules\Tontine\Application\InstallmentGeneratorService::class);
        $campaign->load('marketCalendar');

        $fakeMembership = new \App\Modules\Tontine\Infrastructure\Models\Membership([
            'campaign_id' => $campaign->id,
        ]);
        $fakeMembership->setRelation('campaign', $campaign);

        // Use reflection to call private computeDueDates
        $ref    = new \ReflectionClass($generator);
        $method = $ref->getMethod('computeDueDates');
        $method->setAccessible(true);
        $dates  = $method->invoke($generator, $campaign);

        $preview = array_map(fn ($d, $i) => [
            'sequence_number' => $i + 1,
            'due_date'        => $d->toDateString(),
        ], $dates, array_keys($dates));

        return ApiResponse::success(['installments' => $preview, 'count' => count($preview)]);
    }
}
