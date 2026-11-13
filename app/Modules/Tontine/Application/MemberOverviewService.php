<?php

namespace App\Modules\Tontine\Application;

use App\Shared\BusinessTime;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;

/**
 * Synthèse financière d'un membre pour la fiche back-office.
 *
 * Trois notions à ne pas confondre :
 *   - attendu   : échéances générées (montant contractuel R-MEMB-08), hors annulées (waived)
 *   - encaissé  : paiements confirmés
 *   - disponible: solde ledger MEMBER_SAVINGS (encaissé − retraits versés − payouts réglés)
 *
 * Toutes les agrégations sont groupées par adhésion (pas de N+1).
 */
class MemberOverviewService
{
    public function __construct(
        private readonly LedgerService     $ledger,
        private readonly TrustScoreService $trustScore,
    ) {}

    public function overview(User $user): array
    {
        $memberships = Membership::with(['campaign.marketCalendar'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $ids = $memberships->pluck('id')->all();

        $installments = Installment::whereIn('membership_id', $ids)
            ->where('status', '!=', InstallmentStatus::Waived->value)
            ->selectRaw("membership_id,
                COUNT(*)                                                      as total,
                COUNT(*) FILTER (WHERE due_date <= ?)                         as due,
                COUNT(*) FILTER (WHERE status = 'paid')                       as paid,
                COUNT(*) FILTER (WHERE status = 'late')                       as late,
                COALESCE(SUM(amount_minor), 0)                                as expected_minor,
                MIN(due_date)                                                 as first_due,
                MAX(due_date)                                                 as last_due", [BusinessTime::todayDate()])
            ->groupBy('membership_id')
            ->get()
            ->keyBy('membership_id');

        $paid = Payment::whereIn('membership_id', $ids)
            ->where('status', 'confirmed')
            ->selectRaw('membership_id, COALESCE(SUM(amount_minor), 0) as total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $withdrawn = EarlyWithdrawal::whereIn('membership_id', $ids)
            ->where('status', EarlyWithdrawalStatus::Disbursed->value)
            ->selectRaw('membership_id, COALESCE(SUM(amount_minor), 0) as total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $balances = $this->ledger->balances(array_map(fn ($id) => "MEMBER_SAVINGS:{$id}", $ids));

        // R-LOAN-16 : avances et prêts communautaires de l'emprunteur (avec ou sans adhésion).
        $loans = Loan::where('user_id', $user->id)
            ->where('status', LoanStatus::Active->value)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(principal_minor), 0) as principal, COALESCE(SUM(outstanding_minor), 0) as outstanding')
            ->first();

        $campaigns = $memberships->map(function (Membership $m) use ($installments, $paid, $withdrawn, $balances) {
            $stats    = $installments->get($m->id);
            $campaign = $m->campaign;

            return [
                'membership_id'          => $m->id,
                'campaign_id'            => $campaign?->id,
                'campaign_name'          => $campaign?->name,
                'campaign_status'        => $campaign?->status?->value,
                'status'                 => $m->status->value,
                'status_label'           => $m->status->label(),
                'joined_at'              => ($m->activated_at ?? $m->created_at)?->toIso8601String(),
                'contribution_mode'      => $campaign?->contribution_mode?->value,
                // Montant contractuel : source de vérité unique de l'adhésion (R-MEMB-08)
                'committed_amount_minor' => $m->committed_amount_minor,
                'frequency'              => $campaign?->marketCalendar?->name,
                'start_date'             => $stats?->first_due ?? $campaign?->first_installment_date?->toDateString(),
                'end_date'               => $stats?->last_due,
                'installments_total'     => (int) ($stats->total ?? 0),
                'installments_due'       => (int) ($stats->due ?? 0),
                'installments_paid'      => (int) ($stats->paid ?? 0),
                'installments_late'      => (int) ($stats->late ?? 0),
                'expected_minor'         => (int) ($stats->expected_minor ?? 0),
                'paid_minor'             => (int) ($paid[$m->id] ?? 0),
                'withdrawn_minor'        => (int) ($withdrawn[$m->id] ?? 0),
                'savings_minor'          => (int) ($balances["MEMBER_SAVINGS:{$m->id}"] ?? 0),
            ];
        })->values();

        $upcoming = Installment::with('campaign:id,name')
            ->whereIn('membership_id', $ids)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->orderBy('due_date')
            ->limit(6)
            ->get()
            ->map(fn (Installment $i) => [
                'id'            => $i->id,
                'membership_id' => $i->membership_id,
                'campaign_name' => $i->campaign?->name,
                'due_date'      => $i->due_date?->toDateString(),
                'amount_minor'  => $i->amount_minor,
                'status'        => $i->status->value,
            ]);

        return [
            'kpis' => [
                'paid_minor'           => (int) $campaigns->sum('paid_minor'),
                'expected_minor'       => (int) $campaigns->sum('expected_minor'),
                'installments_total'   => (int) $campaigns->sum('installments_total'),
                'installments_due'     => (int) $campaigns->sum('installments_due'),
                'installments_paid'    => (int) $campaigns->sum('installments_paid'),
                'installments_late'    => (int) $campaigns->sum('installments_late'),
                'savings_minor'        => (int) $campaigns->sum('savings_minor'),
                'withdrawn_minor'      => (int) $campaigns->sum('withdrawn_minor'),
                'loans_active_count'   => (int) ($loans->count ?? 0),
                'loans_principal_minor' => (int) ($loans->principal ?? 0),
                'loans_outstanding_minor' => (int) ($loans->outstanding ?? 0),
                'trust_score'          => $this->trustScore->forMemberships($ids),
            ],
            'campaigns' => $campaigns,
            'upcoming'  => $upcoming,
        ];
    }
}
