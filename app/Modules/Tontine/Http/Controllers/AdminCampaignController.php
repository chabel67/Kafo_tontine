<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Tontine\Application\CampaignService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Http\Requests\CreateCampaignRequest;
use App\Modules\Tontine\Http\Resources\CampaignResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminCampaignController extends Controller
{
    public function __construct(private readonly CampaignService $service) {}

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
        ]);

        $campaign = $this->service->update($campaign, $data);

        return ApiResponse::success(new CampaignResource($campaign));
    }

    public function transition(Request $request, Campaign $campaign): JsonResponse
    {
        $data   = $request->validate([
            'status' => ['required', 'string', 'in:draft,open,active,closed'],
            'reason' => ['sometimes', 'string'],
        ]);

        $newStatus = CampaignStatus::from($data['status']);
        $campaign  = $this->service->transition($campaign, $newStatus, $request->user(), $data['reason'] ?? '');

        return ApiResponse::success(new CampaignResource($campaign));
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
