<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * R-SAV-01 — Épargne réellement disponible d'une adhésion : SEULE source de calcul.
 *
 *   disponible = solde MEMBER_SAVINGS
 *              − retraits anticipés engagés (statuts non terminaux)
 *              − encours des avances sur épargne actives de l'adhésion
 *              − demandes d'avance non terminales de l'adhésion
 *
 * Une même épargne ne peut donc pas servir deux fois (retrait ET avance). Les prêts
 * communautaires (`standard`) sont indépendants des campagnes : affichés, jamais déduits.
 *
 * Utilisé par : retraits (demande, approbation, lancement), avances (instantané d'aide à la
 * décision), aperçu de clôture, liste des retraits, tableaux de bord. Les appelants qui
 * décident (demande, approbation, lancement) verrouillent l'adhésion avant d'appeler.
 */
class SavingsAvailabilityService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @param  string|null  $excludeWithdrawalId    retrait à ne pas compter (re-vérification de lui-même)
     * @param  string|null  $excludeLoanRequestId   demande d'avance à ne pas compter
     */
    public function breakdown(Membership $membership, ?string $excludeWithdrawalId = null, ?string $excludeLoanRequestId = null): array
    {
        // Tontine rotative : aucune épargne individuelle disponible (caisse commune).
        if ($membership->campaign()->first()?->isRotating()) {
            return [
                'paid_minor' => 0, 'savings_minor' => 0, 'withdrawn_minor' => 0, 'committed_minor' => 0,
                'advances_outstanding_minor' => 0, 'advance_requests_minor' => 0, 'reserved_minor' => 0,
                'available_minor' => 0, 'not_applicable' => true,
                'debts' => ['outstanding_minor' => 0, 'overdue_minor' => 0, 'deducted' => false],
                'fees_minor' => 0,
            ];
        }

        $key     = "MEMBER_SAVINGS:{$membership->id}";
        $savings = $this->ledger->balances([$key])[$key];

        $paid = (int) Payment::where('membership_id', $membership->id)
            ->where('status', 'confirmed')
            ->where('purpose', 'contribution')
            ->sum('amount_minor');

        $withdrawn = (int) EarlyWithdrawal::where('membership_id', $membership->id)
            ->where('status', EarlyWithdrawalStatus::Disbursed->value)
            ->sum('amount_minor');

        $committedWithdrawals = (int) EarlyWithdrawal::where('membership_id', $membership->id)
            ->whereIn('status', EarlyWithdrawalStatus::committed())
            ->when($excludeWithdrawalId, fn ($q) => $q->where('id', '!=', $excludeWithdrawalId))
            ->sum('amount_minor');

        $advancesOutstanding = (int) Loan::where('membership_id', $membership->id)
            ->where('product_type', LoanProductType::Advance->value)
            ->where('status', LoanStatus::Active->value)
            ->sum('outstanding_minor');

        $advanceRequests = (int) LoanRequest::where('membership_id', $membership->id)
            ->where('product_type', LoanProductType::Advance->value)
            ->whereIn('status', LoanRequestStatus::open())
            ->when($excludeLoanRequestId, fn ($q) => $q->where('id', '!=', $excludeLoanRequestId))
            ->sum('amount_minor');

        // Prêts communautaires du membre : informatifs (indépendants de la campagne).
        $standardLoans = Loan::where('user_id', $membership->user_id)
            ->where('product_type', LoanProductType::Standard->value)
            ->where('status', LoanStatus::Active->value);
        $standardOutstanding = (int) (clone $standardLoans)->sum('outstanding_minor');
        $standardOverdue     = (int) DB::table('loan_repayment_schedules')
            ->whereIn('loan_id', (clone $standardLoans)->select('id'))
            ->where('status', 'late')
            ->sum(DB::raw('amount_minor - paid_minor'));

        $reserved = $committedWithdrawals + $advancesOutstanding + $advanceRequests;

        return [
            'paid_minor'                 => $paid,
            'savings_minor'              => $savings,
            'withdrawn_minor'            => $withdrawn,
            'committed_minor'            => $committedWithdrawals,
            'advances_outstanding_minor' => $advancesOutstanding,
            'advance_requests_minor'     => $advanceRequests,
            'reserved_minor'             => $reserved,
            'available_minor'            => max(0, $savings - $reserved),
            // Prêts communautaires : affichés, jamais déduits (R-LOAN-16).
            'debts' => [
                'outstanding_minor' => $standardOutstanding,
                'overdue_minor'     => $standardOverdue,
                'deducted'          => false,
            ],
            'fees_minor' => 0,
        ];
    }

    public function available(Membership $membership, ?string $excludeWithdrawalId = null, ?string $excludeLoanRequestId = null): int
    {
        return $this->breakdown($membership, $excludeWithdrawalId, $excludeLoanRequestId)['available_minor'];
    }

    /**
     * Disponible de plusieurs adhésions, calculé par lot (listes : pas de N+1).
     *
     * @param  string[]  $membershipIds
     * @return array<string, int>
     */
    public function availableMany(array $membershipIds): array
    {
        $membershipIds = array_values(array_unique($membershipIds));
        if ($membershipIds === []) {
            return [];
        }

        $balances = $this->ledger->balances(array_map(fn ($id) => "MEMBER_SAVINGS:{$id}", $membershipIds));

        $sumBy = fn ($query) => $query->whereIn('membership_id', $membershipIds)
            ->selectRaw('membership_id, SUM(amount_minor) AS total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $withdrawals = $sumBy(EarlyWithdrawal::whereIn('status', EarlyWithdrawalStatus::committed()));
        $requests    = $sumBy(LoanRequest::where('product_type', LoanProductType::Advance->value)
            ->whereIn('status', LoanRequestStatus::open()));
        $advances    = Loan::whereIn('membership_id', $membershipIds)
            ->where('product_type', LoanProductType::Advance->value)
            ->where('status', LoanStatus::Active->value)
            ->selectRaw('membership_id, SUM(outstanding_minor) AS total')
            ->groupBy('membership_id')
            ->pluck('total', 'membership_id');

        $rotating = Membership::whereIn('id', $membershipIds)
            ->whereHas('campaign', fn ($q) => $q->where('campaign_type', 'rotating'))
            ->pluck('id')->flip();

        $out = [];
        foreach ($membershipIds as $id) {
            if (isset($rotating[$id])) {
                $out[$id] = 0;
                continue;
            }
            $reserved = (int) ($withdrawals[$id] ?? 0) + (int) ($requests[$id] ?? 0) + (int) ($advances[$id] ?? 0);
            $out[$id] = max(0, ($balances["MEMBER_SAVINGS:{$id}"] ?? 0) - $reserved);
        }

        return $out;
    }
}
