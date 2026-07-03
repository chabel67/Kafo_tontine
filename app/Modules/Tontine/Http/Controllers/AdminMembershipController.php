<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Application\MembershipService;
use App\Modules\Tontine\Http\Resources\InstallmentResource;
use App\Modules\Tontine\Http\Resources\MembershipResource;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminMembershipController extends Controller
{
    public function __construct(
        private readonly MembershipService $service,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $memberships = Membership::query()
            ->with(['user', 'campaign'])
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->when($request->query('user_id'),     fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('user', fn ($u) =>
                $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            MembershipResource::collection($memberships)->response()->getData(true)['data'],
            200,
            [
                'total'        => $memberships->total(),
                'per_page'     => $memberships->perPage(),
                'current_page' => $memberships->currentPage(),
                'last_page'    => $memberships->lastPage(),
            ],
        );
    }

    public function enroll(Request $request, Campaign $campaign): JsonResponse
    {
        $rules = ['user_id' => ['required', 'uuid', 'exists:users,id']];

        if ($campaign->contribution_mode?->value === 'custom') {
            $rules['committed_amount'] = ['required', 'integer', 'min:1'];
        }

        $data   = $request->validate($rules);
        $member = User::findOrFail($data['user_id']);

        $membership = $this->service->enroll(
            $member,
            $campaign,
            isset($data['committed_amount']) ? (int) $data['committed_amount'] : null,
        );

        return ApiResponse::created(new MembershipResource($membership->load(['user', 'campaign'])));
    }

    public function validateL1(Request $request, Membership $membership): JsonResponse
    {
        $old        = $membership->status;
        $membership = $this->service->validateL1($membership, $request->user());

        $this->audit->logFromRequest($request, 'membership.validate_l1', 'membership', $membership->id,
            ['status' => $old], ['status' => $membership->status]);

        return ApiResponse::success(new MembershipResource($membership->load(['user', 'campaign'])));
    }

    public function validateL2(Request $request, Membership $membership): JsonResponse
    {
        $old        = $membership->status;
        $membership = $this->service->validateL2($membership, $request->user());

        $this->audit->logFromRequest($request, 'membership.validate_l2', 'membership', $membership->id,
            ['status' => $old], ['status' => $membership->status]);

        return ApiResponse::success(new MembershipResource($membership->load(['user', 'campaign'])));
    }

    public function suspend(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10']]);
        $old  = $membership->status;

        $membership = $this->service->suspend($membership, $request->user(), $data['reason']);

        $this->audit->logFromRequest($request, 'membership.suspend', 'membership', $membership->id,
            ['status' => $old], ['status' => $membership->status, 'reason' => $data['reason']]);

        return ApiResponse::success(new MembershipResource($membership->load(['user', 'campaign'])));
    }

    public function reactivate(Request $request, Membership $membership): JsonResponse
    {
        $old        = $membership->status;
        $membership = $this->service->reactivate($membership, $request->user());

        $this->audit->logFromRequest($request, 'membership.reactivate', 'membership', $membership->id,
            ['status' => $old], ['status' => $membership->status]);

        return ApiResponse::success(new MembershipResource($membership->load(['user', 'campaign'])));
    }

    public function show(Membership $membership): JsonResponse
    {
        $membership->load(['user', 'campaign', 'installments']);

        return ApiResponse::success(new MembershipResource($membership));
    }

    public function installments(Membership $membership): JsonResponse
    {
        $items = $membership->installments()->orderBy('sequence_number')->get();

        return ApiResponse::success(InstallmentResource::collection($items));
    }
}
