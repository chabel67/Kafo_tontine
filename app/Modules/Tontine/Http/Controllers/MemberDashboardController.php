<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Shared\BusinessTime;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Application\TrustScoreService;
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
    public function __construct(private readonly LedgerService $ledger) {}

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

        // Épargne disponible = soldes ledger MEMBER_SAVINGS:{membership} des adhésions
        // actives (R-LEDGER-04), même source que EarlyWithdrawalService::breakdown()
        // (`savings_minor`) et EligibilityService. Diffère du total payé après retrait,
        // versement de clôture ou crédit hors paiement.
        $savingsByMembership = $this->savingsBalances($membershipIds);
        $availableSavingsMinor = array_sum($savingsByMembership);
        // R-SAV-01 : part de l'épargne encore libre (retraits et avances en cours déduits).
        $withdrawableByMembership = app(\App\Modules\Tontine\Application\SavingsAvailabilityService::class)->availableMany($membershipIds);

        // Delta this month
        $savingsDeltaMonth = Payment::whereIn('membership_id', $membershipIds)
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', BusinessTime::now()->startOfMonth()->utc())
            ->sum('amount_minor');

        // Trust score (global across all memberships)
        $trustScore = $this->computeGlobalTrustScore($membershipIds);

        // Next installment (earliest pending or late across all memberships)
        $nextInstallment = Installment::whereIn('membership_id', $membershipIds)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->orderBy('due_date')
            ->first();

        // Active loan
        $activeLoan = Loan::where('user_id', $userId)
            ->where('status', LoanStatus::Active)
            ->latest()
            ->first();

        // Campaigns with progress
        // `total` (historique, lu par l'app Flutter) compte les échéances annulées (`waived`,
        // R-SCHED-04). Additif lot A-bis : `total_effective` = total hors annulées, base de
        // la progression « payées / dues » (même décompte que l'échéancier) ; `waived` = annulées.
        $counts = $this->installmentCounts($membershipIds);
        $campaigns = $memberships->map(function (Membership $m) use ($savingsByMembership, $counts, $withdrawableByMembership) {
            $c = $counts[$m->id] ?? ['total' => 0, 'paid' => 0, 'late' => 0, 'waived' => 0];
            return [
                'id'    => $m->id,
                'name'  => $m->campaign->name ?? '—',
                'paid'  => $c['paid'],
                'total' => $c['total'],
                'total_effective' => $c['total'] - $c['waived'],
                'waived'          => $c['waived'],
                'available_savings_minor' => (int) ($savingsByMembership[$m->id] ?? 0),
                'withdrawable_minor'      => (int) ($withdrawableByMembership[$m->id] ?? 0),
                'allow_early_withdrawals' => (bool) ($m->campaign?->allow_early_withdrawals ?? false),
                'campaign_status'         => $m->campaign?->status?->value,
                // R-FEE-02 : frais d'adhésion restant dus (0 si aucun ou réglés).
                'membership_fee_remaining_minor' => \App\Modules\Tontine\Application\MembershipService::feeStatus($m)['remaining_minor'],
            ];
        });

        return ApiResponse::success([
            // Historique : somme des paiements confirmés (conservé pour compatibilité).
            'total_savings_minor'     => (int) $totalSavingsMinor,
            'total_paid_minor'        => (int) $totalSavingsMinor,
            'available_savings_minor' => (int) $availableSavingsMinor,
            'withdrawable_minor'      => (int) array_sum($withdrawableByMembership),
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

        $availableMap = $this->savingsBalances($membershipIds);
        $withdrawableMap = app(\App\Modules\Tontine\Application\SavingsAvailabilityService::class)->availableMany($membershipIds);

        $result = $memberships->map(fn (Membership $m) => [
            'id'                     => $m->id,
            'status'                 => $m->status->value,
            'committed_amount_minor' => $m->committed_amount_minor,
            'activated_at'           => $m->activated_at?->toDateString(),
            'installments_paid'      => $m->installments->filter(fn ($i) => $i->status === InstallmentStatus::Paid)->count(),
            'installments_late'      => $m->installments->filter(fn ($i) => $i->status === InstallmentStatus::Late)->count(),
            'installments_total'     => $m->installments->count(),
            // Additif lot A-bis : hors échéances annulées (`waived`), cf. dashboard().
            'installments_total_effective' => $m->installments->reject(fn ($i) => $i->status === InstallmentStatus::Waived)->count(),
            'installments_waived'          => $m->installments->filter(fn ($i) => $i->status === InstallmentStatus::Waived)->count(),
            'savings_minor'          => (int) ($savingsMap[$m->id] ?? 0),
            'available_savings_minor' => (int) ($availableMap[$m->id] ?? 0),
            // R-SAV-01 : disponible réel (retraits et avances en cours déduits).
            'withdrawable_minor'      => (int) ($withdrawableMap[$m->id] ?? 0),
            'membership_fee'          => \App\Modules\Tontine\Application\MembershipService::feeStatus($m),
            'next_installment_date'  => $m->installments
                ->filter(fn ($i) => in_array($i->status->value, ['pending', 'late']))
                ->sortBy('due_date')
                ->first()?->due_date?->toDateString(),
            'campaign' => [
                'id'                => $m->campaign->id,
                'name'              => $m->campaign->name,
                'status'            => $m->campaign->status->value,
                'allow_early_withdrawals' => (bool) $m->campaign->allow_early_withdrawals,
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
            ->withPaidMinor()
            ->orderBy('sequence_number')
            ->get()
            ->map(fn (Installment $i) => [
                'id'           => $i->id,
                'sequence'     => $i->sequence_number,
                'due_date'     => $i->due_date->toDateString(),
                'amount_minor' => $i->amount_minor,
                'status'       => $i->status->value,
                'paid_at'      => $i->paid_at?->toDateString(),
                // Additif : paiements partiels
                'paid_minor'      => (int) $i->paid_minor,
                'remaining_minor' => $i->remainingMinor(),
            ]);

        return ApiResponse::success($installments);
    }

    /**
     * Décompte des échéances par adhésion et par statut, en une requête.
     *
     * @param  string[]  $membershipIds
     * @return array<string, array{total: int, paid: int, late: int, waived: int}>
     */
    private function installmentCounts(array $membershipIds): array
    {
        if (empty($membershipIds)) {
            return [];
        }

        return Installment::whereIn('membership_id', $membershipIds)
            ->selectRaw('membership_id,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = ?) as paid,
                COUNT(*) FILTER (WHERE status = ?) as late,
                COUNT(*) FILTER (WHERE status = ?) as waived', [
                InstallmentStatus::Paid->value, InstallmentStatus::Late->value, InstallmentStatus::Waived->value,
            ])
            ->groupBy('membership_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->membership_id => [
                'total'  => (int) $r->total,
                'paid'   => (int) $r->paid,
                'late'   => (int) $r->late,
                'waived' => (int) $r->waived,
            ]])
            ->all();
    }

    /**
     * Soldes MEMBER_SAVINGS par adhésion, via l'API publique du ledger (DA-09).
     *
     * @param  string[]  $membershipIds
     * @return array<string, int>  membership_id → solde
     */
    private function savingsBalances(array $membershipIds): array
    {
        $balances = $this->ledger->balances(array_map(fn ($id) => "MEMBER_SAVINGS:{$id}", $membershipIds));

        $out = [];
        foreach ($membershipIds as $id) {
            $out[$id] = (int) ($balances["MEMBER_SAVINGS:{$id}"] ?? 0);
        }

        return $out;
    }

    private function computeGlobalTrustScore(array $membershipIds): int
    {
        return app(TrustScoreService::class)->forMemberships($membershipIds);
    }
}