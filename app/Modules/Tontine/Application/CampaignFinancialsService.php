<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\BusinessTime;

/**
 * Indicateurs financiers d'une campagne (fiche GET /admin/campaigns/{id}).
 *
 * R-MBR-07 appliqué à la campagne : « encaissé » (paiements confirmés) et
 * « épargne » (soldes ledger MEMBER_SAVINGS, après retraits et versements)
 * ne sont jamais confondus.
 */
class CampaignFinancialsService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{
     *   total_collected_minor: int, confirmed_payments_count: int,
     *   total_savings_minor: int, active_savings_minor: int,
     *   outstanding_advances_minor: int, outstanding_loans_minor: int, active_loans_count: int,
     *   expected_minor: int, late_installments_count: int, overdue_unmarked_installments_count: int
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $memberships = Membership::where('campaign_id', $campaign->id)->get(['id', 'status']);
        $ids         = $memberships->pluck('id')->all();

        $collected = Payment::whereIn('membership_id', $ids)
            ->where('status', 'confirmed')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_minor), 0) as total')
            ->first();

        // Soldes MEMBER_SAVINGS lus via l'API publique du ledger (R-LEDGER-04 : somme des écritures).
        $balances = $this->ledger->balances(array_map(fn ($id) => "MEMBER_SAVINGS:{$id}", $ids));

        $activeIds = $memberships->filter(fn ($m) => $m->status === MembershipStatus::Active)->pluck('id')->all();
        $activeSavings = array_sum(array_map(fn ($id) => $balances["MEMBER_SAVINGS:{$id}"] ?? 0, $activeIds));

        $activeLoans = Loan::where('status', LoanStatus::Active->value)
            ->where(fn ($q) => $q->where('campaign_id', $campaign->id)->orWhereIn('membership_id', $ids))
            ->get(['id', 'product_type', 'outstanding_minor']);

        // Lot A-bis — attendu à date (R-MBR-07 : distinct de l'encaissé) : échéances échues
        // au jour métier (due_date ≤ aujourd'hui), hors annulées (`waived`, R-SCHED-04).
        $today = BusinessTime::todayDate();
        $schedule = Installment::where('campaign_id', $campaign->id)
            ->selectRaw('
                COALESCE(SUM(amount_minor) FILTER (WHERE due_date <= ? AND status <> ?), 0) as expected,
                COUNT(*) FILTER (WHERE status = ?) as late,
                COUNT(*) FILTER (WHERE status = ? AND grace_period_ends_date < ?) as overdue
            ', [
                $today, InstallmentStatus::Waived->value,
                InstallmentStatus::Late->value,
                InstallmentStatus::Pending->value, $today,
            ])
            ->first();

        return [
            'total_collected_minor'      => (int) ($collected->total ?? 0),
            'confirmed_payments_count'   => (int) ($collected->count ?? 0),
            'total_savings_minor'        => (int) array_sum($balances),
            'active_savings_minor'       => (int) $activeSavings,
            'outstanding_advances_minor' => (int) $activeLoans
                ->filter(fn ($l) => $l->product_type === LoanProductType::Advance)
                ->sum('outstanding_minor'),
            'outstanding_loans_minor'    => (int) $activeLoans->sum('outstanding_minor'),
            'active_loans_count'         => $activeLoans->count(),
            'expected_minor'             => (int) ($schedule->expected ?? 0),
            // Échéances au statut `late` (posé par le job de retard B-03, encore absent).
            'late_installments_count'    => (int) ($schedule->late ?? 0),
            // Échéances `pending` dont la période de grâce est dépassée, pas encore marquées
            // `late` tant que le job B-03 manque : à lire avec late_installments_count.
            'overdue_unmarked_installments_count' => (int) ($schedule->overdue ?? 0),
        ];
    }
}
