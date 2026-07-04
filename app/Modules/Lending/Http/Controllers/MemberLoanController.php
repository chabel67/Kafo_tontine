<?php

namespace App\Modules\Lending\Http\Controllers;

use App\Modules\Lending\Application\EligibilityService;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Http\Resources\LoanRepaymentScheduleResource;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MemberLoanController extends Controller
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly LendingService     $service,
    ) {}

    public function eligibility(Request $request, string $membershipId): JsonResponse
    {
        $membership = Membership::where('id', $membershipId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return ApiResponse::success(
            $this->eligibility->snapshotFor($membership, LoanProductType::Advance),
        );
    }

    /**
     * Demande d'avance côté membre. Le product_type est forcé à `advance`
     * et l'éligibilité n'est plus bloquante (R-LOAN-11) — le staff décide.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'purpose'       => ['nullable', 'string', 'max:500'],
        ]);

        $membership = Membership::where('id', $data['membership_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', MembershipStatus::Active)
            ->firstOrFail();

        $loanRequest = $this->service->createRequest(
            [
                'membership'    => $membership,
                'product_type'  => LoanProductType::Advance,
                'amount_minor'  => (int) $data['amount_minor'],
                'purpose'       => $data['purpose'] ?? null,
            ],
            $request->user()->id,
        );

        return ApiResponse::created([
            'id'           => $loanRequest->id,
            'status'       => $loanRequest->status->value,
            'amount_minor' => $loanRequest->amount_minor,
            'product_type' => $loanRequest->product_type->value,
            'created_at'   => $loanRequest->created_at->toIso8601String(),
        ]);
    }

    public function loanSchedule(Request $request, string $loanId): JsonResponse
    {
        $loan = Loan::where('id', $loanId)
            ->whereHas('membership', fn ($q) => $q->where('user_id', $request->user()->id))
            ->firstOrFail();

        return ApiResponse::success(
            LoanRepaymentScheduleResource::collection($loan->schedules()->get()),
        );
    }

    public function myLoans(Request $request): JsonResponse
    {
        $membershipIds = Membership::where('user_id', $request->user()->id)->pluck('id');

        $loans = Loan::whereIn('membership_id', $membershipIds)
            ->with(['membership.campaign', 'repayments'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Loan $loan) => [
                'id'                => $loan->id,
                'reference'         => $loan->reference,
                'principal_minor'   => $loan->principal_minor,
                'outstanding_minor' => $loan->outstanding_minor,
                'repaid_minor'      => $loan->principal_minor - $loan->outstanding_minor,
                'status'            => $loan->status->value,
                'disbursed_at'      => $loan->disbursed_at?->toDateString(),
                'disbursed_channel' => $loan->disbursed_channel,
                'campaign_name'     => $loan->membership?->campaign?->name ?? '—',
                'repayments'        => $loan->repayments
                    ->sortByDesc('created_at')
                    ->map(fn ($r) => [
                        'id'           => $r->id,
                        'amount_minor' => $r->amount_minor,
                        'channel'      => $r->channel,
                        'created_at'   => $r->created_at?->toDateString(),
                    ])->values()->toArray(),
            ]);

        return ApiResponse::success($loans);
    }

    public function myRequests(Request $request): JsonResponse
    {
        $membershipIds = Membership::where('user_id', $request->user()->id)->pluck('id');

        $requests = LoanRequest::whereIn('membership_id', $membershipIds)
            ->with('membership.campaign')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (LoanRequest $lr) => [
                'id'           => $lr->id,
                'status'       => $lr->status->value,
                'status_label' => $lr->status->label(),
                'amount_minor' => $lr->amount_minor,
                'purpose'      => $lr->purpose,
                'campaign'     => $lr->membership->campaign->name ?? '—',
                'created_at'   => $lr->created_at->toDateString(),
                'decided_at'   => $lr->decided_at?->toDateString(),
            ]);

        return ApiResponse::success($requests);
    }
}
