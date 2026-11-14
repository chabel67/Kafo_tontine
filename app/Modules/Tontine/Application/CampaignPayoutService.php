<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\PayoutAlreadySettledException;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Règlement individuel d'un CampaignPayout — temps 2 de la clôture (R-CAMP-10, R-CAMP-12,
 * R-CAMP-14, R-CAMP-16).
 *
 * `settle()`, dans une seule transaction :
 *   1. idempotence (clé de requête) puis verrous : payout → adhésion → avances ;
 *      le statut est relu SOUS verrou (un double règlement concurrent échoue) ;
 *   2. gel si un retrait de l'adhésion est en cours de versement (409) ;
 *   3. recalcul en direct via PayoutCalculator (mêmes règles que l'aperçu) ;
 *   4. remboursement volontaire optionnel d'un prêt communautaire, plafonné au net et
 *      au reste dû, enregistré comme un vrai remboursement (module Lending) ;
 *   5. UNE transaction ledger équilibrée :
 *        DR MEMBER_SAVINGS (compensation + net)
 *        CR LOAN_RECEIVABLE:{avance}            compensation des avances
 *        CR REVENUE_INTEREST / LOAN_RECEIVABLE   remboursement volontaire (intérêts d'abord)
 *        CR CASH_BOX | PSP_FLOAT:{AGRÉGATEUR}        montant versé au membre
 *   6. reliquat d'avance non couvert → EXPENSE_LOSS (R-CAMP-09) ;
 *   7. avances → closed_by_campaign, adhésion → closed, payout → settled + instantané
 *      expliquant l'écart prévisionnel / réel.
 *
 * Un échec (exception) annule tout : le payout reste `pending` et peut être réglé à nouveau.
 */
class CampaignPayoutService
{
    public function __construct(
        private readonly LedgerService    $ledger,
        private readonly PayoutCalculator $calculator,
        private readonly LendingService   $lending,
    ) {}

    /**
     * @param  array{loan_id: string, amount_minor: int}|null  $loanRepayment
     */
    public function settle(
        CampaignPayout $payout,
        string $channel,
        ?string $phone,
        User $actor,
        ?string $idempotencyKey = null,
        ?array $loanRepayment = null,
        ?string $network = null,
    ): CampaignPayout {
        if ($idempotencyKey && ($existing = CampaignPayout::where('idempotency_key', $idempotencyKey)->first())) {
            if ($existing->id !== $payout->id) {
                throw TontineRuleException::idempotencyKeyReused();
            }
            return $existing;
        }

        return DB::transaction(function () use ($payout, $channel, $phone, $actor, $idempotencyKey, $loanRepayment, $network) {
            $payout = CampaignPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== CampaignPayoutStatus::Pending) {
                throw new PayoutAlreadySettledException($payout->id);
            }

            $membership = Membership::whereKey($payout->membership_id)->lockForUpdate()->firstOrFail();
            $advances   = Loan::where('membership_id', $membership->id)
                ->where('product_type', LoanProductType::Advance->value)
                ->where('status', LoanStatus::Active->value)
                ->orderBy('disbursed_at')
                ->lockForUpdate()
                ->get();

            $calc = $this->calculator->compute($membership);
            if ($calc['withdrawals_in_progress'] !== []) {
                throw TontineRuleException::withdrawalPayoutInProgress($calc['withdrawals_in_progress'][0]['reference']);
            }

            $savingsMinor = $calc['savings_minor'];
            $offset       = $calc['advance_offset_minor'];
            $net          = $calc['net_amount_minor'];
            $shortfall    = $calc['shortfall_minor'];
            $compensated  = min($savingsMinor, $offset);

            $savingsKey = "MEMBER_SAVINGS:{$membership->id}";
            $floatKey   = TreasuryAccount::key($channel);
            $this->ledger->openAccount(key: $savingsKey, type: AccountType::MemberSavings, ownerId: $membership->user_id);
            $this->ledger->openAccount(key: $floatKey, type: TreasuryAccount::type($channel));

            $legs = [];
            $remaining = $compensated;
            foreach ($advances as $advance) {
                if ($remaining <= 0) break;
                $apply = min($advance->outstanding_minor, $remaining);
                if ($apply <= 0) continue;
                $legs[] = ['account' => "LOAN_RECEIVABLE:{$advance->id}", 'type' => 'credit', 'amount' => $apply];
                $remaining -= $apply;
            }

            // R-CAMP-14 : remboursement volontaire d'un prêt communautaire sur le net.
            $repayment = null;
            $repaid    = 0;
            if ($loanRepayment !== null) {
                $repaid = (int) $loanRepayment['amount_minor'];
                if ($repaid <= 0) {
                    throw TontineRuleException::invalidLoanRepayment('Le montant du remboursement doit être supérieur à zéro.');
                }
                if ($repaid > $net) {
                    throw TontineRuleException::invalidLoanRepayment("Le remboursement ({$repaid} XOF) dépasse le montant net à verser ({$net} XOF).");
                }
                [$repayment, $credits] = $this->lending->repayFromSettlement(
                    $loanRepayment['loan_id'], $membership->user_id, $repaid, $payout->id, $payout->reference, $actor->id,
                );
                array_push($legs, ...$credits);
            }

            $paidOut = $net - $repaid;
            if ($paidOut > 0) {
                $legs[] = ['account' => $floatKey, 'type' => 'credit', 'amount' => $paidOut];
            }

            $txn = null;
            if ($compensated + $net > 0) {
                array_unshift($legs, ['account' => $savingsKey, 'type' => 'debit', 'amount' => $compensated + $net]);
                $txn = $this->ledger->post(
                    legs:        $legs,
                    reference:   $this->ledger->nextReference(),
                    description: "Payout de clôture {$payout->reference}",
                    createdById: $actor->id,
                    metadata:    array_filter([
                        'payout_id'          => $payout->id,
                        'campaign_id'        => $payout->campaign_id,
                        'membership_id'      => $membership->id,
                        'loan_repayment_id'  => $repayment?->id,
                    ]),
                );
            }

            // R-CAMP-09 : reliquat en EXPENSE_LOSS
            if ($shortfall > 0) {
                $this->ledger->openAccount(key: 'EXPENSE_LOSS', type: AccountType::ExpenseLoss);
                $lossLegs  = [['account' => 'EXPENSE_LOSS', 'type' => 'debit', 'amount' => $shortfall]];
                $covered   = $compensated;
                $remaining = $shortfall;
                foreach ($advances as $advance) {
                    if ($remaining <= 0) break;
                    $alreadyCovered = min($advance->outstanding_minor, $covered);
                    $covered       -= $alreadyCovered;
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
                    metadata:    ['payout_id' => $payout->id, 'reason' => 'campaign_closure_shortfall'],
                );

                Log::warning("Payout {$payout->reference}: shortfall of {$shortfall} XOF (savings={$savingsMinor}, advances={$offset})");
            }

            foreach ($advances as $advance) {
                $advance->update(['status' => LoanStatus::ClosedByCampaign->value, 'outstanding_minor' => 0]);
            }

            $membership->update(['status' => MembershipStatus::Closed->value]);

            // R-CAMP-16 : écart prévisionnel (clôture) / réel (règlement), expliqué.
            $metadata = $payout->metadata ?? [];
            $metadata['settled_snapshot'] = [
                'savings_at_settlement'       => $savingsMinor,
                'offset_at_settlement'        => $offset,
                'shortfall'                   => $shortfall,
                'savings_delta'               => $savingsMinor - $payout->savings_minor,
                'offset_delta'                => $offset - $payout->advance_offset_minor,
                'withdrawals_disbursed_delta' => $calc['withdrawals_disbursed_minor'] - $payout->withdrawals_disbursed_minor,
                'net_delta'                   => $net - $payout->net_amount_minor,
                'loan_repayment_minor'        => $repaid,
                'paid_out_minor'              => $paidOut,
            ];

            $payout->update([
                'idempotency_key'             => $idempotencyKey,
                'status'                      => CampaignPayoutStatus::Settled->value,
                'settled_channel'             => $channel,
                'settled_phone'               => $channel === 'cash' ? null : $phone,
                'settled_network'             => $channel === 'cash' ? null : $network,
                'settled_by'                  => $actor->id,
                'settled_at'                  => now(),
                'savings_minor'               => $savingsMinor,
                'advance_offset_minor'        => $offset,
                'withdrawals_disbursed_minor' => $calc['withdrawals_disbursed_minor'],
                'net_amount_minor'            => $net,
                'loan_repayment_minor'        => $repaid,
                'loan_repayment_id'           => $repayment?->id,
                'ledger_transaction_id'       => $txn?->id,
                'metadata'                    => $metadata,
            ]);

            return $payout->fresh();
        });
    }

    /**
     * Données d'aide au règlement (écran payout) : calcul en direct + prêts communautaires
     * actifs du membre (remboursement volontaire possible, jamais automatique).
     */
    public function settlementContext(CampaignPayout $payout): array
    {
        $membership = $payout->membership;

        return [
            'live'           => $payout->status === CampaignPayoutStatus::Pending ? $this->calculator->compute($membership) : null,
            'standard_loans' => $payout->status === CampaignPayoutStatus::Pending
                ? $this->lending->activeStandardLoansForUser($membership->user_id)
                : [],
        ];
    }

    /**
     * Annulation super_admin (R-CAMP-11). L'adhésion n'est pas réactivée.
     */
    public function cancel(CampaignPayout $payout, string $reason, User $actor): CampaignPayout
    {
        return DB::transaction(function () use ($payout, $reason, $actor) {
            $payout = CampaignPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== CampaignPayoutStatus::Pending) {
                throw new PayoutAlreadySettledException($payout->id);
            }

            $payout->update([
                'status'           => CampaignPayoutStatus::Cancelled->value,
                'cancelled_reason' => $reason,
                'settled_by'       => $actor->id,
                'settled_at'       => now(),
            ]);

            return $payout->fresh();
        });
    }
}
