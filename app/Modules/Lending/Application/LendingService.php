<?php

namespace App\Modules\Lending\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
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

class LendingService
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly LedgerService $ledger,
        private readonly RepaymentScheduleGeneratorService $scheduler,
    ) {}

    /**
     * Crée une demande de prêt polymorphe.
     *
     * Payload attendu (validé côté FormRequest) :
     * - `product_type` : `advance` | `standard`
     * - `amount_minor`, `purpose`
     * - Si `advance` : `membership` requis, `campaign_id` inféré de la membership
     * - Si `standard` : `membership` requis (cible), `campaign_id` optionnel,
     *   `periodicity`, `installments_count`, `first_due_date` requis
     *   (ou `custom_due_dates` non vides si periodicity=custom),
     *   `interest_rate_bps` optionnel
     *
     * R-LOAN-11 : aucune vérif dure d'éligibilité — snapshot informatif seulement.
     */
    public function createRequest(array $payload, string $createdById): LoanRequest
    {
        $productType = $payload['product_type'] instanceof LoanProductType
            ? $payload['product_type']
            : LoanProductType::from($payload['product_type']);

        $membership = $payload['membership'] ?? null;
        $campaignId = $productType === LoanProductType::Advance
            ? ($membership?->campaign_id)
            : ($payload['campaign_id'] ?? null);

        $snapshot = $this->eligibility->snapshotFor($membership, $productType);

        return LoanRequest::create([
            'membership_id'        => $membership?->id,
            'campaign_id'          => $campaignId,
            'product_type'         => $productType,
            'amount_minor'         => (int) $payload['amount_minor'],
            'purpose'              => $payload['purpose'] ?? null,
            'status'               => LoanRequestStatus::Pending,
            'interest_rate_bps'    => $payload['interest_rate_bps'] ?? null,
            'periodicity'          => $payload['periodicity'] ?? null,
            'installments_count'   => $payload['installments_count'] ?? null,
            'first_due_date'       => $payload['first_due_date'] ?? null,
            'custom_due_dates'     => $payload['custom_due_dates'] ?? null,
            'eligibility_snapshot' => $snapshot,
            'created_by'           => $createdById,
        ]);
    }

    public function approve(LoanRequest $request, string $userId): LoanRequest
    {
        if ($request->status !== LoanRequestStatus::Pending) {
            throw new \RuntimeException('La demande n\'est pas en attente d\'approbation.');
        }

        // High-value advance requires countersign
        $nextStatus = $request->amount_minor >= EligibilityService::COUNTERSIGN_THRESHOLD
            ? LoanRequestStatus::Countersigning
            : LoanRequestStatus::Approved;

        $request->update([
            'status'      => $nextStatus,
            'decided_by'  => $userId,
            'decided_at'  => now(),
        ]);

        return $request;
    }

    public function countersign(LoanRequest $request, string $userId): LoanRequest
    {
        if ($request->status !== LoanRequestStatus::Countersigning) {
            throw new \RuntimeException('La demande n\'est pas en attente de contre-signature.');
        }

        if ($request->decided_by === $userId) {
            throw new \RuntimeException('Le même utilisateur ne peut pas approuver et contre-signer.');
        }

        $request->update([
            'status'            => LoanRequestStatus::Countersigned,
            'countersigned_by'  => $userId,
            'countersigned_at'  => now(),
        ]);

        return $request;
    }

    public function reject(LoanRequest $request, string $userId, string $reason): LoanRequest
    {
        if (! in_array($request->status, [
            LoanRequestStatus::Pending,
            LoanRequestStatus::Approved,
            LoanRequestStatus::Countersigning,
            LoanRequestStatus::Countersigned,
        ])) {
            throw new \RuntimeException('La demande ne peut plus être refusée dans son état actuel.');
        }

        $request->update([
            'status'          => LoanRequestStatus::Rejected,
            'decided_by'      => $userId,
            'decided_at'      => now(),
            'rejected_reason' => $reason,
        ]);

        return $request;
    }

    public function disburse(
        LoanRequest $request,
        string $disbursedById,
        string $channel,
        ?string $phone,
    ): Loan {
        if (! $request->status->isDisbursable()) {
            throw new \RuntimeException('La demande n\'est pas prête pour le décaissement.');
        }

        $membership = $request->membership_id
            ? $request->membership()->with(['installments'])->firstOrFail()
            : null;

        // R-LOAN-11 : plus de blocage éligibilité. On (re)pose juste le
        // snapshot informatif au moment du décaissement (audit).
        $productType    = $request->product_type;
        $freshSnapshot  = $this->eligibility->snapshotFor($membership, $productType);
        $request->update(['eligibility_snapshot' => $freshSnapshot]);

        return DB::transaction(function () use ($request, $membership, $disbursedById, $channel, $phone, $productType) {
            $reference = $this->nextReference();

            $loan = Loan::create([
                'reference'          => $reference,
                'loan_request_id'    => $request->id,
                'membership_id'      => $request->membership_id,
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
                'disbursed_by'       => $disbursedById,
                'disbursed_at'       => now(),
            ]);

            $request->update([
                'status'        => LoanRequestStatus::Disbursed,
                'disbursed_by'  => $disbursedById,
                'disbursed_at'  => now(),
            ]);

            // Ledger: DR LOAN_RECEIVABLE:{loan_id} / CR channel float
            $receivableKey = "LOAN_RECEIVABLE:{$loan->id}";
            $this->ledger->openAccount(
                key: $receivableKey,
                type: AccountType::LoanReceivable,
                ownerId: $membership?->user_id,
                description: "{$productType->label()} {$reference}" . ($membership?->user?->full_name ? " — {$membership->user->full_name}" : ''),
            );

            $floatKey = $channel === 'cash' ? 'CASH_BOX' : "MOMO_FLOAT:{$channel}";
            $this->ledger->openAccount(key: $floatKey, type: $channel === 'cash' ? AccountType::CashBox : AccountType::MomoFloat);

            $txnRef = $this->ledger->nextReference();
            $this->ledger->post(
                legs: [
                    ['account' => $receivableKey, 'type' => 'debit',  'amount' => $request->amount_minor],
                    ['account' => $floatKey,       'type' => 'credit', 'amount' => $request->amount_minor],
                ],
                reference:   $txnRef,
                description: "Décaissement {$productType->label()} {$reference}",
                createdById: $disbursedById,
                metadata:    ['loan_id' => $loan->id, 'product_type' => $productType->value],
            );

            // Prêt standard : génère l'échéancier de remboursement
            if ($productType->requiresSchedule()) {
                $this->scheduler->generate($loan->fresh());
            }

            if ($channel !== 'cash') {
                Log::info("MoMo OUTBOUND [{$channel}] {$loan->reference} → {$phone} : {$request->amount_minor} XOF");
            }

            return $loan->fresh();
        });
    }

    public function recordRepayment(
        Loan $loan,
        int $amountMinor,
        string $channel,
        ?string $notes,
        string $recordedById,
    ): LoanRepayment {
        if ($loan->status !== LoanStatus::Active) {
            throw new \RuntimeException('Ce prêt n\'est plus actif.');
        }

        return DB::transaction(function () use ($loan, $amountMinor, $channel, $notes, $recordedById) {
            $effective = min($amountMinor, $loan->outstanding_minor);

            $repayment = LoanRepayment::create([
                'loan_id'      => $loan->id,
                'amount_minor' => $effective,
                'channel'      => $channel,
                'notes'        => $notes,
                'recorded_by'  => $recordedById,
            ]);

            $receivableKey = "LOAN_RECEIVABLE:{$loan->id}";
            $floatKey      = $channel === 'cash' ? 'CASH_BOX' : "MOMO_FLOAT:{$channel}";
            $this->ledger->openAccount(key: $floatKey, type: $channel === 'cash' ? AccountType::CashBox : AccountType::MomoFloat);

            // Prêt standard avec intérêt : split principal/intérêt via le schedule.
            // Prêt sans échéancier ou sans intérêt : legs simples DR float / CR receivable.
            $legs = [['account' => $floatKey, 'type' => 'debit', 'amount' => $effective]];

            if ($loan->product_type === LoanProductType::Standard && $loan->interest_total_minor > 0) {
                $split = $this->scheduler->applyPayment($loan, $effective);
                if ($split['interest'] > 0) {
                    $this->ledger->openAccount(key: 'REVENUE_INTEREST', type: AccountType::RevenueInterest);
                    $legs[] = ['account' => 'REVENUE_INTEREST', 'type' => 'credit', 'amount' => $split['interest']];
                }
                if ($split['principal'] > 0) {
                    $legs[] = ['account' => $receivableKey, 'type' => 'credit', 'amount' => $split['principal']];
                }
                // Le split couvre exactement $effective (garanti par applyPayment
                // qui n'affecte jamais plus que ce qu'il reçoit). Si un reliquat
                // subsiste (paiement > total dû), on l'impute au principal receivable.
                $matched = $split['principal'] + $split['interest'];
                if ($matched < $effective) {
                    $legs[] = ['account' => $receivableKey, 'type' => 'credit', 'amount' => $effective - $matched];
                }
            } else {
                if ($loan->product_type === LoanProductType::Standard) {
                    // Standard sans intérêt : on met à jour le schedule quand même
                    $this->scheduler->applyPayment($loan, $effective);
                }
                $legs[] = ['account' => $receivableKey, 'type' => 'credit', 'amount' => $effective];
            }

            $txnRef = $this->ledger->nextReference();
            $this->ledger->post(
                legs:        $legs,
                reference:   $txnRef,
                description: "Remboursement {$loan->product_type->label()} {$loan->reference}",
                createdById: $recordedById,
                metadata:    ['loan_id' => $loan->id, 'repayment_id' => $repayment->id],
            );

            $newOutstanding = $loan->outstanding_minor - $effective;
            $loan->update([
                'outstanding_minor' => $newOutstanding,
                'status'            => $newOutstanding <= 0 ? LoanStatus::Repaid : LoanStatus::Active,
            ]);

            return $repayment;
        });
    }

    public function writeOff(Loan $loan, string $userId, string $reason): Loan
    {
        if ($loan->status !== LoanStatus::Active) {
            throw new \RuntimeException('Seuls les prêts actifs peuvent être passés en perte.');
        }

        return DB::transaction(function () use ($loan, $userId, $reason) {
            $outstanding = $loan->outstanding_minor;

            if ($outstanding > 0) {
                $receivableKey = "LOAN_RECEIVABLE:{$loan->id}";
                $this->ledger->openAccount(key: 'EXPENSE_LOSS', type: AccountType::ExpenseLoss);

                $txnRef = $this->ledger->nextReference();
                $this->ledger->post(
                    legs: [
                        ['account' => 'EXPENSE_LOSS',  'type' => 'debit',  'amount' => $outstanding],
                        ['account' => $receivableKey,   'type' => 'credit', 'amount' => $outstanding],
                    ],
                    reference:   $txnRef,
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

            // Suspend membership (R-LOAN-07)
            $membership = $loan->membership;
            if ($membership->status === \App\Modules\Tontine\Domain\Enums\MembershipStatus::Active) {
                $membership->update([
                    'status'           => MembershipStatus::Suspended,
                    'suspended_by'     => $userId,
                    'suspended_at'     => now(),
                    'suspension_reason' => "Write-off avance {$loan->reference}: {$reason}",
                ]);
            }

            return $loan;
        });
    }

    private function nextReference(): string
    {
        $year  = now()->year;
        $count = Loan::whereYear('created_at', $year)->count() + 1;
        return sprintf('LOAN-%d-%06d', $year, $count);
    }
}
