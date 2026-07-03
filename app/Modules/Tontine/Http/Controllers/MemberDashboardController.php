<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MemberDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $memberships = Membership::with('campaign')
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active)
            ->get();

        $membershipIds = $memberships->pluck('id')->all();

        // Total savings = sum of confirmed payments across all active memberships
        $totalSavingsMinor = Payment::whereIn('membership_id', $membershipIds)
            ->where('status', 'confirmed')
            ->sum('amount_minor');

        // Delta this month
        $savingsDeltaMonth = Payment::whereIn('membership_id', $membershipIds)
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', now()->startOfMonth())
            ->sum('amount_minor');

        // Trust score (global across all memberships)
        $trustScore = $this->computeGlobalTrustScore($membershipIds);

        // Next installment (earliest pending or late across all memberships)
        $nextInstallment = Installment::whereIn('membership_id', $membershipIds)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->orderBy('due_date')
            ->first();

        // Active loan
        $activeLoan = Loan::whereIn('membership_id', $membershipIds)
            ->where('status', LoanStatus::Active)
            ->latest()
            ->first();

        // Campaigns with progress
        $campaigns = $memberships->map(function (Membership $m) {
            $total = $m->installments()->count();
            $paid  = $m->installments()->where('status', InstallmentStatus::Paid)->count();
            return [
                'id'    => $m->id,
                'name'  => $m->campaign->name ?? '—',
                'paid'  => $paid,
                'total' => $total,
            ];
        });

        return ApiResponse::success([
            'total_savings_minor'     => (int) $totalSavingsMinor,
            'savings_delta_month'     => (int) $savingsDeltaMonth,
            'trust_score'             => $trustScore,
            'active_campaigns_count'  => $memberships->count(),
            'next_installment'        => $nextInstallment ? [
                'membership_id' => $nextInstallment->membership_id,
                'amount_minor'  => $nextInstallment->amount_minor,
                'due_date'      => $nextInstallment->due_date->toDateString(),
                'status'        => $nextInstallment->status->value,
            ] : null,
            'active_loan' => $activeLoan ? [
                'outstanding_minor' => $activeLoan->outstanding_minor,
                'next_due_date'     => null, // repayment schedule à implémenter
            ] : null,
            'campaigns' => $campaigns->values(),
        ]);
    }

    public function memberships(Request $request): JsonResponse
    {
        $memberships = Membership::with(['campaign', 'installments'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Savings per membership in one query (cross-module direct query acceptable here)
        $membershipIds = $memberships->pluck('id')->all();
        $savingsMap = \App\Modules\Payments\Infrastructure\Models\Payment::whereIn('membership_id', $membershipIds)
            ->where('status', 'confirmed')
            ->selectRaw('membership_id, SUM(amount_minor) as total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $result = $memberships->map(fn (Membership $m) => [
            'id'                     => $m->id,
            'status'                 => $m->status->value,
            'committed_amount_minor' => $m->committed_amount_minor,
            'activated_at'           => $m->activated_at?->toDateString(),
            'installments_paid'      => $m->installments->filter(fn ($i) => $i->status === InstallmentStatus::Paid)->count(),
            'installments_late'      => $m->installments->filter(fn ($i) => $i->status === InstallmentStatus::Late)->count(),
            'installments_total'     => $m->installments->count(),
            'savings_minor'          => (int) ($savingsMap[$m->id] ?? 0),
            'next_installment_date'  => $m->installments
                ->filter(fn ($i) => in_array($i->status->value, ['pending', 'late']))
                ->sortBy('due_date')
                ->first()?->due_date?->toDateString(),
            'campaign' => [
                'id'                => $m->campaign->id,
                'name'              => $m->campaign->name,
                'status'            => $m->campaign->status->value,
                'installment_count' => $m->campaign->duration_installments,
                'total_amount_minor'=> $m->committed_amount_minor * $m->campaign->duration_installments,
            ],
        ]);

        return ApiResponse::success($result);
    }

    public function installments(Request $request, string $membershipId): JsonResponse
    {
        $membership = Membership::where('id', $membershipId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $installments = $membership->installments()
            ->orderBy('sequence_number')
            ->get()
            ->map(fn (Installment $i) => [
                'id'           => $i->id,
                'sequence'     => $i->sequence_number,
                'due_date'     => $i->due_date->toDateString(),
                'amount_minor' => $i->amount_minor,
                'status'       => $i->status->value,
                'paid_at'      => $i->paid_at?->toDateString(),
            ]);

        return ApiResponse::success($installments);
    }

    private function computeGlobalTrustScore(array $membershipIds): int
    {
        if (empty($membershipIds)) {
            return 80;
        }

        $totalDue = Installment::whereIn('membership_id', $membershipIds)
            ->where('due_date', '<=', now())
            ->count();

        if ($totalDue === 0) {
            return 80;
        }

        $everLate    = Installment::whereIn('membership_id', $membershipIds)
            ->whereNotNull('late_marked_at')
            ->count();
        $currentLate = Installment::whereIn('membership_id', $membershipIds)
            ->where('status', InstallmentStatus::Late)
            ->count();

        return max(0, min(100, 80 - ($everLate * 10) - ($currentLate * 20)));
    }
}