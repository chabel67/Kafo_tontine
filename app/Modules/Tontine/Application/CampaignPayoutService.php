<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\PayoutAlreadySettledException;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Règlement effectif d'un CampaignPayout (R-CAMP-10, R-CAMP-12).
 *
 * `settle()` :
 *   1. Guard status (pending uniquement)
 *   2. Recalcul savings en direct (protection concurrence R-CAMP-12)
 *   3. Écrit UNE transaction ledger équilibrée qui compense simultanément
 *      MEMBER_SAVINGS, LOAN_RECEIVABLE (advances) et canal de versement
 *   4. Si déficitaire (advance > savings) : écriture séparée EXPENSE_LOSS
 *      pour le reliquat (R-CAMP-09)
 *   5. Passe les advances liées à ClosedByCampaign, outstanding_minor=0
 *   6. Passe la Membership à Closed, le CampaignPayout à Settled
 *
 * `cancel()` — annulation super_admin, ré-active la membership.
 */
class CampaignPayoutService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function settle(
        CampaignPayout $payout,
        string $channel,
        ?string $phone,
        User $actor,
    ): CampaignPayout {
        if ($payout->status !== CampaignPayoutStatus::Pending) {
            throw new PayoutAlreadySettledException($payout->id);
        }

        return DB::transaction(function () use ($payout, $channel, $phone, $actor) {
            $membership = $payout->membership()->lockForUpdate()->first();
            $advances   = Loan::where('membership_id', $membership->id)
                ->where('product_type', LoanProductType::Advance->value)
                ->where('status', LoanStatus::Active->value)
                ->lockForUpdate()
                ->get();

            // R-CAMP-12 : recalcul du savings en direct (protection contre
            // paiements confirmés entre la clôture et le settlement).
            $savingsKey    = "MEMBER_SAVINGS:{$membership->id}";
            $savingsMinor  = $this->ledger->balance($savingsKey);
            $offset        = (int) $advances->sum('outstanding_minor');
            $net           = max(0, $savingsMinor - $offset);
            $shortfall     = max(0, $offset - $savingsMinor);
            $savingsDelta  = $savingsMinor - $payout->savings_minor;

            $this->ledger->openAccount(key: $savingsKey, type: AccountType::MemberSavings, ownerId: $membership->user_id);
            $floatKey = $channel === 'cash' ? 'CASH_BOX' : "MOMO_FLOAT:{$channel}";
            $this->ledger->openAccount(key: $floatKey, type: $channel === 'cash' ? AccountType::CashBox : AccountType::MomoFloat);

            // Legs de la transaction principale :
            //   DR MEMBER_SAVINGS   = min(savings, offset+net) — apure l'épargne
            //   CR LOAN_RECEIVABLE  = compensation avance (par advance)
            //   CR CASH/MOMO        = net décaissé
            $compensated = min($savingsMinor, $offset);
            $legs = [
                ['account' => $savingsKey, 'type' => 'debit', 'amount' => $compensated + $net],
            ];
            $remaining = $compensated;
            foreach ($advances as $advance) {
                if ($remaining <= 0) break;
                $apply = min($advance->outstanding_minor, $remaining);
                if ($apply <= 0) continue;
                $receivableKey = "LOAN_RECEIVABLE:{$advance->id}";
                $legs[] = ['account' => $receivableKey, 'type' => 'credit', 'amount' => $apply];
                $remaining -= $apply;
            }
            if ($net > 0) {
                $legs[] = ['account' => $floatKey, 'type' => 'credit', 'amount' => $net];
            }

            if ($compensated + $net > 0) {
                $this->ledger->post(
                    legs:        $legs,
                    reference:   $this->ledger->nextReference(),
                    description: "Payout de clôture {$payout->reference}",
                    createdById: $actor->id,
                    metadata:    ['payout_id' => $payout->id, 'campaign_id' => $payout->campaign_id],
                );
            }

            // R-CAMP-09 : reliquat en EXPENSE_LOSS
            if ($shortfall > 0) {
                $this->ledger->openAccount(key: 'EXPENSE_LOSS', type: AccountType::ExpenseLoss);
                $lossLegs = [
                    ['account' => 'EXPENSE_LOSS', 'type' => 'debit', 'amount' => $shortfall],
                ];
                $remaining = $shortfall;
                foreach ($advances as $advance) {
                    if ($remaining <= 0) break;
                    // Déjà compensé partiellement dans la transaction principale
                    $alreadyCovered = min($advance->outstanding_minor, $compensated);
                    $stillOwed      = $advance->outstanding_minor - $alreadyCovered;
                    if ($stillOwed <= 0) continue;
                    $apply = min($stillOwed, $remaining);
                    $lossLegs[] = ['account' => "LOAN_RECEIVABLE:{$advance->id}", 'type' => 'credit', 'amount' => $apply];
                    $remaining -= $apply;
                }
                $this->ledger->post(
                    legs:        $lossLegs,
                    reference:   $this->ledger->nextReference(),
                    description: "Reliquat non couvert — payout {$payout->reference}",
                    createdById: $actor->id,
                    metadata:    [
                        'payout_id' => $payout->id,
                        'reason'    => 'campaign_closure_shortfall',
                    ],
                );

                Log::warning("Payout {$payout->reference}: shortfall of {$shortfall} XOF (savings={$savingsMinor}, advances={$offset})");
            }

            // Marque toutes les advances comme soldées par la campagne.
            foreach ($advances as $advance) {
                $advance->update([
                    'status'            => LoanStatus::ClosedByCampaign->value,
                    'outstanding_minor' => 0,
                ]);
            }

            $membership->update(['status' => MembershipStatus::Closed->value]);

            $metadata = $payout->metadata ?? [];
            $metadata['settled_snapshot'] = [
                'savings_at_settlement' => $savingsMinor,
                'offset_at_settlement'  => $offset,
                'shortfall'             => $shortfall,
                'savings_delta'         => $savingsDelta,
            ];

            $payout->update([
                'status'           => CampaignPayoutStatus::Settled->value,
                'settled_channel'  => $channel,
                'settled_phone'    => $phone,
                'settled_by'       => $actor->id,
                'settled_at'       => now(),
                'net_amount_minor' => $net,
                'savings_minor'    => $savingsMinor,
                'advance_offset_minor' => $offset,
                'metadata'         => $metadata,
            ]);

            return $payout->fresh();
        });
    }

    /**
     * Annulation super_admin. Réactive la membership (rare — erreurs de saisie).
     */
    public function cancel(CampaignPayout $payout, string $reason, User $actor): CampaignPayout
    {
        if ($payout->status !== CampaignPayoutStatus::Pending) {
            throw new PayoutAlreadySettledException($payout->id);
        }

        return DB::transaction(function () use ($payout, $reason, $actor) {
            $payout->update([
                'status'           => CampaignPayoutStatus::Cancelled->value,
                'cancelled_reason' => $reason,
                'settled_by'       => $actor->id,
                'settled_at'       => now(),
            ]);
            // Note : ne re-ré-active pas la membership : elle n'a jamais été
            // passée à Closed dans cette phase (le settle est ce qui la ferme).
            return $payout->fresh();
        });
    }
}
