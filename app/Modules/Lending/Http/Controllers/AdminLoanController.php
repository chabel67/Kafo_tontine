<?php

namespace App\Modules\Lending\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Lending\Application\EligibilityService;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Http\Resources\LoanRequestResource;
use App\Modules\Lending\Http\Resources\LoanResource;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminLoanController extends Controller
{
    public function __construct(
        private readonly LendingService     $service,
        private readonly EligibilityService $eligibility,
        private readonly AuditService       $audit,
    ) {}

    // ── Loan Requests ──────────────────────────────────────────────────────

    public function indexRequests(Request $request): JsonResponse
    {
        $requests = LoanRequest::with(['membership.user', 'membership.campaign', 'decidedBy', 'countersignedBy'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('membership_id'), fn ($q, $id) => $q->where('membership_id', $id))
            ->when($request->query('search'), fn ($q, $s) =>
                $q->whereHas('membership.user', fn ($u) =>
                    $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                )
            )
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            LoanRequestResource::collection($requests)->response()->getData(true)['data'],
            200,
            [
                'total'        => $requests->total(),
                'per_page'     => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
            ],
        );
    }

    public function showRequest(LoanRequest $loanRequest): JsonResponse
    {
        $loanRequest->load(['membership.user', 'membership.campaign', 'decidedBy', 'countersignedBy', 'loan.repayments']);

        return ApiResponse::success(new LoanRequestResource($loanRequest));
    }

    public function createRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'uuid', 'exists:memberships,id'],
            'amount_minor'  => ['required', 'integer', 'min:1'],
            'purpose'       => ['nullable', 'string', 'max:500'],
        ]);

        $membership  = Membership::findOrFail($data['membership_id']);
        $loanRequest = $this->service->createRequest(
            $membership,
            (int) $data['amount_minor'],
            $data['purpose'] ?? null,
            $request->user()->id,
        );

        return ApiResponse::created(new LoanRequestResource($loanRequest->load(['membership.user', 'membership.campaign'])));
    }

    public function approve(Request $request, LoanRequest $loanRequest): JsonResponse
    {
        $this->service->approve($loanRequest, $request->user()->id);

        $this->audit->logFromRequest($request, 'loan_request.approve', 'loan_request', $loanRequest->id,
            ['status' => 'pending'], ['status' => $loanRequest->fresh()->status?->value]);

        return ApiResponse::success(new LoanRequestResource($loanRequest->fresh(['membership.user', 'membership.campaign', 'decidedBy'])));
    }

    public function countersign(Request $request, LoanRequest $loanRequest): JsonResponse
    {
        $this->service->countersign($loanRequest, $request->user()->id);

        $this->audit->logFromRequest($request, 'loan_request.countersign', 'loan_request', $loanRequest->id,
            [], ['status' => $loanRequest->fresh()->status?->value]);

        return ApiResponse::success(new LoanRequestResource($loanRequest->fresh(['membership.user', 'membership.campaign', 'decidedBy', 'countersignedBy'])));
    }

    public function reject(Request $request, LoanRequest $loanRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $old = $loanRequest->status?->value;
        $this->service->reject($loanRequest, $request->user()->id, $data['reason']);

        $this->audit->logFromRequest($request, 'loan_request.reject', 'loan_request', $loanRequest->id,
            ['status' => $old], ['status' => 'rejected', 'reason' => $data['reason']]);

        return ApiResponse::success(new LoanRequestResource($loanRequest->fresh(['membership.user', 'membership.campaign'])));
    }

    public function disburse(Request $request, LoanRequest $loanRequest): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:cash,mtn,orange,moov,wave'],
            'phone'   => ['nullable', 'string', 'regex:/^\+\d{7,15}$/'],
        ]);

        $loan = $this->service->disburse(
            $loanRequest,
            $request->user()->id,
            $data['channel'],
            $data['phone'] ?? null,
        );

        $this->audit->logFromRequest($request, 'loan.disburse', 'loan', $loan->id,
            [], ['amount_minor' => $loan->principal_minor, 'channel' => $data['channel']]);

        return ApiResponse::created(new LoanResource($loan->load(['membership.user', 'membership.campaign'])));
    }

    // ── Eligibility check ──────────────────────────────────────────────────

    public function checkEligibility(Membership $membership): JsonResponse
    {
        $snapshot = $this->eligibility->check($membership);
        return ApiResponse::success($snapshot);
    }

    // ── Loans ──────────────────────────────────────────────────────────────

    public function indexLoans(Request $request): JsonResponse
    {
        $loans = Loan::with(['membership.user', 'membership.campaign'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('membership_id'), fn ($q, $id) => $q->where('membership_id', $id))
            ->when($request->query('search'), fn ($q, $s) =>
                $q->whereHas('membership.user', fn ($u) =>
                    $u->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                )
            )
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            LoanResource::collection($loans)->response()->getData(true)['data'],
            200,
            [
                'total'        => $loans->total(),
                'per_page'     => $loans->perPage(),
                'current_page' => $loans->currentPage(),
                'last_page'    => $loans->lastPage(),
            ],
        );
    }

    public function showLoan(Loan $loan): JsonResponse
    {
        $loan->load(['membership.user', 'membership.campaign', 'repayments', 'loanRequest']);
        return ApiResponse::success(new LoanResource($loan));
    }

    public function recordRepayment(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'channel'      => ['required', 'string', 'in:cash,mtn,orange,moov,wave'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);

        $repayment = $this->service->recordRepayment(
            $loan,
            (int) $data['amount_minor'],
            $data['channel'],
            $data['notes'] ?? null,
            $request->user()->id,
        );

        return ApiResponse::created([
            'repayment'    => [
                'id'           => $repayment->id,
                'amount_minor' => $repayment->amount_minor,
            ],
            'loan_status'       => $loan->fresh()->status?->value,
            'outstanding_minor' => $loan->fresh()->outstanding_minor,
        ]);
    }

    public function writeOff(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $loan = $this->service->writeOff($loan, $request->user()->id, $data['reason']);

        $this->audit->logFromRequest($request, 'loan.write_off', 'loan', $loan->id,
            ['status' => 'active'], ['status' => 'written_off', 'reason' => $data['reason']]);

        return ApiResponse::success(new LoanResource($loan->fresh(['membership.user', 'membership.campaign'])));
    }
}
