<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Tontine\Http\Resources\CampaignPayoutResource;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MemberCampaignPayoutController extends Controller
{
    public function myPayouts(Request $request): JsonResponse
    {
        $membershipIds = Membership::where('user_id', $request->user()->id)->pluck('id');

        $payouts = CampaignPayout::whereIn('membership_id', $membershipIds)
            ->with(['campaign'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(CampaignPayoutResource::collection($payouts));
    }

    public function show(Request $request, CampaignPayout $payout): JsonResponse
    {
        $ownsMembership = Membership::where('id', $payout->membership_id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $ownsMembership) {
            return ApiResponse::error('forbidden', 'This payout does not belong to you.', 403);
        }

        $payout->load(['campaign']);
        return ApiResponse::success(new CampaignPayoutResource($payout));
    }
}
