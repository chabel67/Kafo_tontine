<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;

/**
 * Lot 6 — rapprochement de la caisse commune d'une tontine rotative.
 *
 * Recalcule le solde attendu de `ROTATING_POOL:{campagne}` à partir des tables opérationnelles
 * (sans lire le grand livre), puis le compare au solde comptable :
 *
 *   entrées  = paiements confirmés des adhésions − compléments (D-R5) − pénalités (D-R10) couverts
 *   sorties  = cagnottes des tours versés + remboursements de départ + reliquats versés
 *   attendu  = entrées − sorties ;  écart = solde comptable − attendu (doit être 0)
 *
 * Contrôles annexes : tour versé sans écriture, tentative réussie sans écriture, tours validés
 * dépassant la caisse, compte d'épargne ouvert à tort pour une adhésion rotative.
 */
class RotationReconciliationService
{
    public function __construct(private readonly RotationTrackingService $tracking) {}

    public function reconcile(Campaign $campaign): array
    {
        $membershipIds = $campaign->memberships()->pluck('id');

        $paymentsIn = (int) Payment::whereIn('membership_id', $membershipIds)
            ->where('status', 'confirmed')
            ->where('purpose', 'contribution')
            ->sum('amount_minor');

        $fees = 0;
        $penalties = 0;
        Installment::where('campaign_id', $campaign->id)->withPaidMinor()->get()
            ->each(function (Installment $i) use (&$fees, &$penalties) {
                $split      = $i->splitPaid((int) $i->paid_minor);
                $fees      += $split['fee'];
                $penalties += $split['penalty'];
            });

        $roundsOut = (int) RotationRound::where('campaign_id', $campaign->id)
            ->where('status', RotationRoundStatus::Completed->value)
            ->sum('expected_minor');

        $otherOut = (int) RotationPayoutAttempt::where('campaign_id', $campaign->id)
            ->whereIn('kind', [RotationPayoutAttempt::KIND_DEPARTURE, RotationPayoutAttempt::KIND_SURPLUS])
            ->where('status', RotationPayoutAttempt::SUCCEEDED)
            ->sum('amount_minor');

        $revenue  = (int) $campaign->rotation_surplus_revenue_minor;   // reliquat passé en produit (D-R11)
        $inflow   = $paymentsIn - $fees - $penalties;
        $expected = $inflow - $roundsOut - $otherOut - $revenue;
        $ledger   = $this->tracking->poolBalance($campaign->id);

        $anomalies = [];
        $orphanRounds = RotationRound::where('campaign_id', $campaign->id)
            ->where('status', RotationRoundStatus::Completed->value)->whereNull('ledger_transaction_id')->pluck('round_number');
        if ($orphanRounds->isNotEmpty()) {
            $anomalies[] = 'Tour(s) versé(s) sans écriture comptable : ' . $orphanRounds->implode(', ') . '.';
        }
        $orphanAttempts = RotationPayoutAttempt::where('campaign_id', $campaign->id)
            ->where('status', RotationPayoutAttempt::SUCCEEDED)->whereNull('ledger_transaction_id')->count();
        if ($orphanAttempts > 0) {
            $anomalies[] = "{$orphanAttempts} versement(s) confirmé(s) sans écriture comptable.";
        }
        $reserved = (int) RotationRound::where('campaign_id', $campaign->id)
            ->whereIn('status', [RotationRoundStatus::Approved->value, RotationRoundStatus::Processing->value, RotationRoundStatus::Failed->value])
            ->sum('expected_minor');
        if ($reserved > $ledger) {
            $anomalies[] = "Tours validés ou en versement ({$reserved} XOF) supérieurs à la caisse ({$ledger} XOF).";
        }
        $savingsAccounts = \Illuminate\Support\Facades\DB::table('ledger_accounts')
            ->whereIn('key', $membershipIds->map(fn ($id) => "MEMBER_SAVINGS:{$id}"))->count();
        if ($savingsAccounts > 0) {
            $anomalies[] = "{$savingsAccounts} compte(s) d'épargne individuelle ouvert(s) pour cette tontine (ne devrait pas exister).";
        }
        if ($ledger !== $expected) {
            array_unshift($anomalies, "Écart de caisse : solde comptable {$ledger} XOF, attendu {$expected} XOF.");
        }

        return [
            'campaign_id'          => $campaign->id,
            'ledger_balance_minor' => $ledger,
            'expected_minor'       => $expected,
            'difference_minor'     => $ledger - $expected,
            'balanced'             => $anomalies === [],
            'detail' => [
                'payments_minor'           => $paymentsIn,
                'fees_minor'               => $fees,
                'penalties_minor'          => $penalties,
                'contributions_minor'      => $inflow,
                'rounds_paid_out_minor'    => $roundsOut,
                'other_paid_out_minor'     => $otherOut,
                'surplus_revenue_minor'    => $revenue,
                'reserved_rounds_minor'    => $reserved,
            ],
            'anomalies'            => $anomalies,
            'checked_at'           => now()->toIso8601String(),
        ];
    }
}
