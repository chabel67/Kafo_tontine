<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Tontine\Application\CampaignClosureService;
use App\Modules\Tontine\Application\CampaignPayoutService;
use App\Modules\Tontine\Http\Requests\SettleCampaignPayoutRequest;
use App\Modules\Tontine\Http\Resources\CampaignPayoutResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminCampaignPayoutController extends Controller
{
    public function __construct(
        private readonly CampaignClosureService $closure,
        private readonly CampaignPayoutService  $payouts,
        private readonly AuditService           $audit,
    ) {}

    public function preview(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success([
            'campaign' => [
                'id'   => $campaign->id,
                'name' => $campaign->name,
            ],
            'payouts' => $this->closure->preview($campaign)->values(),
        ]);
    }

    public function close(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $result = $this->closure->close($campaign, $request->user(), $data['reason']);

        $this->audit->logFromRequest($request, 'campaign.close', 'tontine_campaign', $campaign->id,
            ['status' => 'active'],
            [
                'status'                    => 'closed',
                'reason'                    => $data['reason'],
                'payouts_count'             => $result['payouts_count'],
                'cancelled_advances_count'  => $result['cancelled_advances_count'],
            ],
        );

        return ApiResponse::success([
            'campaign_id'              => $campaign->id,
            'payouts_count'            => $result['payouts_count'],
            'cancelled_advances_count' => $result['cancelled_advances_count'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $payouts = CampaignPayout::query()
            ->with(['campaign', 'membership.user'])
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) =>
                $q->whereHas('membership.user', fn ($u) =>
                    $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                )
            )
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            CampaignPayoutResource::collection($payouts)->response()->getData(true)['data'],
            200,
            [
                'total'        => $payouts->total(),
                'per_page'     => $payouts->perPage(),
                'current_page' => $payouts->currentPage(),
                'last_page'    => $payouts->lastPage(),
            ],
        );
    }

    public function show(CampaignPayout $payout): JsonResponse
    {
        $payout->load(['campaign', 'membership.user']);
        return ApiResponse::success(new CampaignPayoutResource($payout));
    }

    public function settle(SettleCampaignPayoutRequest $request, CampaignPayout $payout): JsonResponse
    {
        $data = $request->validated();
        $settled = $this->payouts->settle($payout, $data['channel'], $data['phone'] ?? null, $request->user());

        $this->audit->logFromRequest($request, 'campaign_payout.settle', 'campaign_payout', $payout->id,
            ['status' => 'pending'],
            [
                'status'  => 'settled',
                'channel' => $data['channel'],
                'net'     => $settled->net_amount_minor,
            ],
        );

        return ApiResponse::success(new CampaignPayoutResource($settled->load(['campaign', 'membership.user'])));
    }

    public function cancel(Request $request, CampaignPayout $payout): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $cancelled = $this->payouts->cancel($payout, $data['reason'], $request->user());

        $this->audit->logFromRequest($request, 'campaign_payout.cancel', 'campaign_payout', $payout->id,
            ['status' => 'pending'],
            ['status' => 'cancelled', 'reason' => $data['reason']],
        );

        return ApiResponse::success(new CampaignPayoutResource($cancelled->load(['campaign', 'membership.user'])));
    }
}
