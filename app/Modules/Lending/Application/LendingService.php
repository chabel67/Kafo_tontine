<?php

namespace App\Modules\Lending\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Lending\Domain\Exceptions\LendingRuleException;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Lending\Domain\Enums\LoanPeriodicity;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRepayment;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prêts : avances sur épargne (rattachées à une adhésion, compensées à la clôture) et
 * prêts communautaires (rattachés au membre, indépendants des campagnes — R-LOAN-16).
 *
 * Concurrence : chaque transition relit la ligne sous `lockForUpdate` (ni double
 * approbation, ni double décaissement, ni double remboursement). Pour une avance, l'ordre
 * des verrous est celui de la clôture : campagne (partagé) → adhésion → demande.
 * Idempotence : clé optionnelle sur la demande et sur le remboursement.
 * R-LOAN-11 / D1 : aucune vérification d'éligibilité bloquante — un dépassement du
 * disponible (R-SAV-01) est signalé dans l'instantané et l'audit, le staff décide.
 */
class LendingService
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly LedgerService $ledger,
        private readonly RepaymentScheduleGeneratorService $scheduler,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Crée une demande de prêt.
     *
     * Payload (validé par FormRequest) :
     * - `product_type` : `advance` | `standard`, `amount_minor`, `purpose`, `idempotency_key` (option)
     * - `advance`  : `membership` (adhésion active d'une campagne active)
     * - `standard` : `user` (membre), `periodicity`, `installments_count`, `first_due_date`
     *   (ou `custom_due_dates`), `interest_rate_bps` optionnel. Jamais rattaché à une campagne.
     */
    public function createRequest(array $payload, string $createdById): LoanRequest
    {
        $productType = $payload['product_type'] instanceof LoanProductType
            ? $payload['product_type']
            : LoanProductType::from($payload['product_type']);

        $key = $payload['idempotency_key'] ?? null;
        if ($key && ($existing = LoanRequest::where('idempotency_key', $key)->first())) {
            if ($existing->created_by !== $createdById) {
                throw LendingRuleException::idempotencyKeyReused();
            }
            return $existing;
        }

        return DB::transaction(function () use ($payload, $productType, $createdById, $key) {
            $membership = null;
            if ($productType === LoanProductType::Advance) {
                $membership = $this->lockAdvanceMembership($payload['membership']->id);
                $userId     = $membership->user_id;
                $campaignId = $membership->campaign_id;
            } else {
                $userId     = $payload['user']?->id ?? $payload['membership']?->user_id;
                $campaignId = null;
            }

            $amount = (int) $payload['amount_minor'];

            return LoanRequest::create([
                'idempotency_key'      => $key,
                'membership_id'        => $membership?->id,
                'user_id'              => $userId,
                'campaign_id'          => $campaignId,
                'product_type'         => $productType,
                'amount_minor'         => $amount,
                'purpose'              => $payload['purpose'] ?? null,
                'status'               => LoanRequestStatus::Pending,
                'interest_rate_bps'    => $payload['interest_rate_bps'] ?? null,
                'periodicity'          => $payload['periodicity'] ?? null,
                'installments_count'   => $payload['installments_count'] ?? null,
                'first_due_date'       => $payload['first_due_date'] ?? null,
                'custom_due_dates'     => $payload['custom_due_dates'] ?? null,
                'eligibility_snapshot' => $this->eligibility->snapshotFor($membership, $productType, $amount),
                'created_by'           => $createdById,
            ]);
        });
    }

    public function approve(LoanRequest $request, string $userId): LoanRequest
    {
        return DB::transaction(function () use ($request, $userId) {
            $this->lockMembershipOf($request);
            $locked = LoanRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LoanRequestStatus::Pending) {
                throw LendingRuleException::invalidRequestTransition('La demande n\'est pas en attente d\'approbation.');
            }

            $nextStatus = $locked->amount_minor >= EligibilityService::COUNTERSIGN_THRESHOLD
                ? LoanRequestStatus::Countersigning
                : LoanRequestStatus::Approved;

            $locked->update([
                'status'               => $nextStatus,
                'decided_by'           => $userId,
                'decided_at'           => now(),
                'eligibility_snapshot' => $this->freshSnapshot($locked),
            ]);

            $this->notifyMember($locked, 'Demande de prêt approuvée',
                "Votre demande de {$this->money($locked->amount_minor)} est approuvée. Le versement sera effectué prochainement.");

            return $locked->fresh();
        });
    }

    public function countersign(LoanRequest $request, string $userId): LoanRequest
    {
        return DB::transaction(function () use ($request, $userId) {
            $locked = LoanRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LoanRequestStatus::Countersigning) {
                throw LendingRuleException::invalidRequestTransition('La demande n\'est pas en attente de contre-signature.');
            }
            if ($locked->decided_by === $userId) {
                throw LendingRuleException::makerChecker();
            }

            $locked->update([
                'status'           => LoanRequestStatus::Countersigned,
                'countersigned_by' => $userId,
                'countersigned_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function reject(LoanRequest $request, string $userId, string $reason, bool $notify = true): LoanRequest
    {
        return DB::transaction(function () use ($request, $userId, $reason, $notify) {
            $locked = LoanRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status->value, LoanRequestStatus::open(), true)) {
                throw LendingRuleException::invalidRequestTransition('La demande ne peut plus être refusée dans son état actuel.');
            }

            $locked->update([
                'status'          => LoanRequestStatus::Rejected,
                'decided_by'      => $userId,
                'decided_at'      => now(),
                'rejected_reason' => $reason,
            ]);

            if ($notify) {
                $this->notifyMember($locked, 'Demande de prêt refusée', "Votre demande de {$this->money($locked->amount_minor)} a été refusée. Motif : {$reason}");
            }

            return $locked->fresh();
        });
    }

    public function disburse(
        LoanRequest $request,
        string $disbursedById,
        string $channel,
        ?string $phone,
        ?string $network = null,
    ): Loan {
        $loan = DB::transaction(function () use ($request, $disbursedById, $channel, $phone, $network) {
            // Avance : campagne toujours active et adhésion verrouillée (ordre de la clôture).
            $membership = $this->lockMembershipOf($request);

            // Relecture sous verrou : un second décaissement concurrent échoue ici.
            $request = LoanRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $request->status->isDisbursable()) {
                throw LendingRuleException::invalidRequestTransition('La demande n\'est pas prête pour le décaissement.');
            }

            // R-LOAN-04 (sans blocage, R-LOAN-11/D1) : instantané R-SAV recalculé au décaissement.
            $productType = $request->product_type;
            $request->update(['eligibility_snapshot' => $this->freshSnapshot($request)]);

            $reference = $this->nextReference();

            $loan = Loan::create([
                'reference'          => $reference,
                'loan_request_id'    => $request->id,
                'membership_id'      => $request->membership_id,
                'user_id'            => $request->user_id ?? $membership?->user_id,
                'product_type'       => $productType,
                'campaign_id'        => $request->campaign_id,
                'principal_minor'    => $request->amount_minor,
                'outstanding_minor'  => $request->amount_minor,
                'status'             => LoanStatus::Active,
                'interest_rate_bps'  => $request->interest_rate_bps,
                'periodicity'        => $request->periodicity,
                'installments_count' => $request->installments_count,
                'first_due_date'     => $request->first_due_date,
                'custom_due_dates'   => $request->custom_due_dates,
                'disbursed_channel'  => $channel,
                'disbursed_phone'    => $phone,
                'disbursed_network'  => $channel === 'cash' ? null : $network,
                'disbursed_by'       => $disbursedById,
                'disbursed_at'       => now(),
            ]);

            $request->update([
                'status'        => LoanRequestStatus::Disbursed,
                'disbursed_by'  => $disbursedById,
                'disbursed_at'  => now(),
            ]);

            // Ledger: DR LOAN_RECEIVABLE:{loan_id} / CR compte de trésorerie du canal
            $receivableKey = "LOAN_RECEIVABLE:{$loan->id}";
            $borrower      = $loan->user_id ? User::find($loan->user_id) : null;
            $this->ledger->openAccount(
                key: $receivableKey,
                type: AccountType::LoanReceivable,
                ownerId: $loan->user_id,
                description: "{$productType->label()} {$reference}" . ($borrower?->full_name ? " — {$borrower->full_name}" : ''),
            );

            $floatKey = TreasuryAccount::key($channel);
            $this->ledger->openAccount(key: $floatKey, type: TreasuryAccount::type($channel));

            $this->ledger->post(
                legs: [
                    ['account' => $receivableKey, 'type' => 'debit',  'amount' => $request->amount_minor],
                    ['account' => $floatKey,       'type' => 'credit', 'amount' => $request->amount_minor],
                ],
                reference:   $this->ledger->nextReference(),
                description: "Décaissement {$productType->label()} {$reference}",
                createdById: $disbursedById,
                metadata:    ['loan_id' => $loan->id, 'product_type' => $productType->value],
            );

            // Prêt standard : génère l'échéancier de remboursement
            if ($productType->requiresSchedule()) {
                $this->scheduler->generate($loan->fresh());
            }

            if ($channel !== 'cash') {
                Log::info("MoMo OUTBOUND [{$channel}] {$loan->reference} : {$request->amount_minor} XOF");
            }

            return $loan->fresh();
        });

        $this->notifications->notify($loan->user_id, 'loan.disbursed', 'Prêt versé',
            "Votre {$loan->product_type->label()} {$loan->reference} de {$this->money($loan->principal_minor)} a été versé.", '/prets');

        return $loan;
    }

    /**
     * Remboursement encaissé (espèces ou Mobile Money).
     * Refusé au-delà du reste dû (principal + intérêts restants) : aucun trop-perçu silencieux.
     */
    public function recordRepayment(
        Loan $loan,
        int $amountMinor,
        string $channel,
        ?string $notes,
        string $recordedById,
        ?string $idempotencyKey = null,
    ): LoanRepayment {
        if ($idempotencyKey && ($existing = LoanRepayment::where('idempotency_key', $idempotencyKey)->first())) {
            if ($existing->loan_id !== $loan->id) {
                throw LendingRuleException::idempotencyKeyReused();
            }
            return $existing;
        }

        return DB::transaction(function () use ($loan, $amountMinor, $channel, $notes, $recordedById, $idempotencyKey) {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $floatKey = TreasuryAccount::key($channel);
            $this->ledger->openAccount(key: $floatKey, type: TreasuryAccount::type($channel));

            [$repayment, $credits] = $this->applyRepayment($loan, $amountMinor, [
                'idempotency_key' => $idempotencyKey,
                'channel'         => $channel,
                'notes'           => $notes,
                'recorded_by'     => $recordedById,
            ]);

            $this->ledger->post(
                legs:        [['account' => $floatKey, 'type' => 'debit', 'amount' => $repayment->amount_minor], ...$credits],
                reference:   $this->ledger->nextReference(),
                description: "Remboursement {$loan->product_type->label()} {$loan->reference}",
                createdById: $recordedById,
                metadata:    ['loan_id' => $loan->id, 'repayment_id' => $repayment->id],
            );

            return $repayment;
        });
    }

    /**
     * R-CAMP-14 : remboursement volontaire d'un prêt communautaire prélevé sur le montant
     * d'un payout de clôture. N'écrit PAS au grand livre : renvoie les jambes créditrices
     * (intérêts puis principal) que le règlement intègre à SA transaction équilibrée
     * (débit MEMBER_SAVINGS). À appeler dans la transaction de règlement.
     *
     * @return array{0: LoanRepayment, 1: array<int, array{account:string,type:string,amount:int}>}
     */
    public function repayFromSettlement(string $loanId, string $userId, int $amountMinor, string $payoutId, string $payoutReference, string $recordedById): array
    {
        $loan = Loan::whereKey($loanId)->lockForUpdate()->first();

        if (! $loan || $loan->product_type !== LoanProductType::Standard || $loan->user_id !== $userId) {
            throw LendingRuleException::invalidRequestTransition('Ce prêt n\'est pas un prêt communautaire de ce membre.');
        }

        return $this->applyRepayment($loan, $amountMinor, [
            'channel'            => 'campaign_payout',
            'campaign_payout_id' => $payoutId,
            'notes'              => "Prélevé sur le règlement de clôture {$payoutReference}",
            'recorded_by'        => $recordedById,
        ]);
    }

    /** Prêts communautaires actifs d'un membre, avec reste dû (remboursement volontaire au règlement). */
    public function activeStandardLoansForUser(string $userId): array
    {
        return Loan::where('user_id', $userId)
            ->where('product_type', LoanProductType::Standard->value)
            ->where('status', LoanStatus::Active->value)
            ->orderBy('disbursed_at')
            ->get()
            ->map(fn (Loan $l) => [
                'id'                => $l->id,
                'reference'         => $l->reference,
                'principal_minor'   => $l->principal_minor,
                'outstanding_minor' => $l->outstanding_minor,
                'total_due_minor'   => $l->totalDueMinor(),
                'next_due_date'     => $l->next_due_date?->toDateString(),
            ])
            ->all();
    }

    public function writeOff(Loan $loan, string $userId, string $reason): Loan
    {
        return DB::transaction(function () use ($loan, $userId, $reason) {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== LoanStatus::Active) {
                throw LendingRuleException::loanNotActive();
            }

            $outstanding = $loan->outstanding_minor;

            if ($outstanding > 0) {
                $receivableKey = "LOAN_RECEIVABLE:{$loan->id}";
                $this->ledger->openAccount(key: 'EXPENSE_LOSS', type: AccountType::ExpenseLoss);

                $this->ledger->post(
                    legs: [
                        ['account' => 'EXPENSE_LOSS',  'type' => 'debit',  'amount' => $outstanding],
                        ['account' => $receivableKey,   'type' => 'credit', 'amount' => $outstanding],
                    ],
                    reference:   $this->ledger->nextReference(),
                    description: "Write-off {$loan->reference}",
                    createdById: $userId,
                    metadata:    ['loan_id' => $loan->id, 'reason' => $reason],
                );
            }

            $loan->update([
                'status'             => LoanStatus::WrittenOff,
                'outstanding_minor'  => 0,
                'written_off_by'     => $userId,
                'written_off_at'     => now(),
                'written_off_reason' => $reason,
            ]);

            // R-LOAN-07 : suspend l'adhésion liée (avance). Un prêt communautaire n'en a pas.
            $membership = $loan->membership_id ? Membership::whereKey($loan->membership_id)->lockForUpdate()->first() : null;
            if ($membership && $membership->status === MembershipStatus::Active) {
                $membership->update([
                    'status'            => MembershipStatus::Suspended,
                    'suspended_by'      => $userId,
                    'suspended_at'      => now(),
                    'suspension_reason' => "Write-off {$loan->reference}: {$reason}",
                ]);
            }

            return $loan;
        });
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /**
     * Impute un remboursement sur un prêt verrouillé : échéancier (intérêts puis principal,
     * R-LOAN-14), encours de principal, statut. Renvoie la ligne de remboursement et les
     * jambes créditrices du grand livre (le débit est fourni par l'appelant).
     *
     * @return array{0: LoanRepayment, 1: array<int, array{account:string,type:string,amount:int}>}
     */
    private function applyRepayment(Loan $loan, int $amountMinor, array $attributes): array
    {
        if ($loan->status !== LoanStatus::Active) {
            throw LendingRuleException::loanNotActive();
        }
        if ($amountMinor <= 0) {
            throw LendingRuleException::amountExceedsDue($amountMinor, $loan->totalDueMinor());
        }

        $due = $loan->totalDueMinor();
        if ($amountMinor > $due) {
            throw LendingRuleException::amountExceedsDue($amountMinor, $due);
        }

        $principal = $amountMinor;
        $interest  = 0;
        if ($loan->product_type === LoanProductType::Standard) {
            $split     = $this->scheduler->applyPayment($loan, $amountMinor);
            $interest  = $split['interest'];
            // Ce que l'échéancier n'a pas absorbé (échéances renoncées…) réduit le principal.
            $principal = $amountMinor - $interest;
        }

        $repayment = LoanRepayment::create([
            'loan_id'      => $loan->id,
            'amount_minor' => $amountMinor,
            ...$attributes,
        ]);

        $credits = [];
        if ($interest > 0) {
            $this->ledger->openAccount(key: 'REVENUE_INTEREST', type: AccountType::RevenueInterest);
            $credits[] = ['account' => 'REVENUE_INTEREST', 'type' => 'credit', 'amount' => $interest];
        }
        if ($principal > 0) {
            $credits[] = ['account' => "LOAN_RECEIVABLE:{$loan->id}", 'type' => 'credit', 'amount' => $principal];
        }

        // `outstanding_minor` = principal restant uniquement (les intérêts ne le réduisent pas).
        $loan->update(['outstanding_minor' => max(0, $loan->outstanding_minor - $principal)]);
        $loan->refresh();
        if ($loan->totalDueMinor() <= 0) {
            $loan->update(['status' => LoanStatus::Repaid, 'outstanding_minor' => 0]);
        }

        return [$repayment, $credits];
    }

    /**
     * Avance : verrouille campagne (partagé, doit être active) puis adhésion (doit être active).
     * Prêt communautaire : rien à verrouiller côté campagne.
     */
    private function lockMembershipOf(LoanRequest $request): ?Membership
    {
        if ($request->product_type !== LoanProductType::Advance || ! $request->membership_id) {
            return null;
        }

        return $this->lockAdvanceMembership($request->membership_id);
    }

    private function lockAdvanceMembership(string $membershipId): Membership
    {
        $campaignId = Membership::whereKey($membershipId)->value('campaign_id');
        $campaign   = Campaign::whereKey($campaignId)->sharedLock()->firstOrFail();
        // Une avance s'adosse à une épargne individuelle : impossible sur une tontine rotative.
        if ($campaign->isRotating()) {
            throw \App\Modules\Tontine\Domain\Exceptions\TontineRuleException::notForRotatingCampaign();
        }
        if ($campaign->status !== CampaignStatus::Active) {
            throw LendingRuleException::campaignNotActive();
        }

        $membership = Membership::whereKey($membershipId)->lockForUpdate()->firstOrFail();
        if ($membership->status !== MembershipStatus::Active) {
            throw LendingRuleException::membershipNotActive();
        }

        return $membership;
    }

    private function freshSnapshot(LoanRequest $request): array
    {
        $membership = $request->membership_id ? Membership::find($request->membership_id) : null;

        return $this->eligibility->snapshotFor($membership, $request->product_type, $request->amount_minor, $request->id);
    }

    private function notifyMember(LoanRequest $request, string $title, string $body): void
    {
        if ($request->user_id) {
            $this->notifications->notify($request->user_id, 'loan_request.' . $request->status->value, $title, $body, '/prets');
        }
    }

    private function money(int $minor): string
    {
        return number_format($minor, 0, ',', ' ') . ' FCFA';
    }

    private function nextReference(): string
    {
        return \App\Shared\Reference::next('LOAN', \App\Shared\Reference::LOAN);
    }
}
