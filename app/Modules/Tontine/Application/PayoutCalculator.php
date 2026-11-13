<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;

/**
 * Calcul du règlement de clôture d'une adhésion — UNE seule fonction pour l'aperçu,
 * la clôture (montants prévisionnels) et le règlement (montants réels) : R-CAMP-16.
 *
 *   épargne              = solde MEMBER_SAVINGS (retraits déjà versés déjà déduits par le ledger)
 *   retraits en cours    = retraits `payout_processing` (argent peut-être déjà remis)
 *   à compenser          = encours des avances sur épargne actives de l'adhésion
 *   net                  = max(0, épargne − retraits en cours − à compenser)
 *   reliquat             = max(0, à compenser − (épargne − retraits en cours))  → EXPENSE_LOSS
 *
 * Les prêts communautaires ne sont jamais déduits (R-LOAN-16) ; un remboursement volontaire
 * se décide au règlement (R-CAMP-14).
 */
class PayoutCalculator
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function compute(Membership $membership): array
    {
        $key     = "MEMBER_SAVINGS:{$membership->id}";
        $savings = $this->ledger->balances([$key])[$key];

        $offset = (int) Loan::where('membership_id', $membership->id)
            ->where('product_type', LoanProductType::Advance->value)
            ->where('status', LoanStatus::Active->value)
            ->sum('outstanding_minor');

        $withdrawals = EarlyWithdrawal::where('membership_id', $membership->id)
            ->whereIn('status', [EarlyWithdrawalStatus::Disbursed->value, EarlyWithdrawalStatus::PayoutProcessing->value])
            ->get(['id', 'reference', 'status', 'amount_minor']);

        $disbursed  = (int) $withdrawals->where('status', EarlyWithdrawalStatus::Disbursed)->sum('amount_minor');
        $inProgress = $withdrawals->where('status', EarlyWithdrawalStatus::PayoutProcessing);
        $inProgressMinor = (int) $inProgress->sum('amount_minor');

        $base = $savings - $inProgressMinor;

        return [
            'savings_minor'                => $savings,
            'withdrawals_disbursed_minor'  => $disbursed,
            'withdrawals_in_progress_minor' => $inProgressMinor,
            'withdrawals_in_progress'      => $inProgress->map(fn ($w) => ['id' => $w->id, 'reference' => $w->reference, 'amount_minor' => $w->amount_minor])->values()->all(),
            'advance_offset_minor'         => $offset,
            'net_amount_minor'             => max(0, $base - $offset),
            'shortfall_minor'              => max(0, $offset - max(0, $base)),
        ];
    }
}
