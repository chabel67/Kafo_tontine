<?php

namespace App\Modules\Lending\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Domain\Exceptions\LoanIneligibleException;
use App\Modules\Lending\Domain\Exceptions\LoanRecheckFailedException;
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
    ) {}

    public function createRequest(
        Membership $membership,
        int $amountMinor,
        ?string $purpose,
        string $createdById,
    ): LoanRequest {
        $snapshot = $this->eligibility->check($membership);

        if (! $snapshot['eligible']) {
            throw new LoanIneligibleException($snapshot['reasons']);
        }

        if ($amountMinor > $snapshot['max_amount_minor']) {
            throw new LoanIneligibleException(["Montant demandé ({$amountMinor}) dépasse le plafond ({$snapshot['max_amount_minor']})"]);
        }

        return LoanRequest::create([
            'membership_id'         => $membership->id,
            'amount_minor'          => $amountMinor,
            'purpose'               => $purpose,
            'status'                => LoanRequestStatus::Pending,
            'eligibility_snapshot'  => $snapshot,
            'created_by'            => $createdById,
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

        // Re-check eligibility before disbursement (R-LOAN-04)
        $membership = $request->membership()->with(['installments'])->firstOrFail();
        $snapshot   = $this->eligibility->check($membership);

        if (! $snapshot['eligible']) {
            $request->update(['status' => LoanRequestStatus::Rejected, 'rejected_reason' => 'recheck_failed: ' . implode('; ', $snapshot['reasons'])]);
            throw new LoanRecheckFailedException($snapshot['reasons']);
        }

        if ($request->amount_minor > $snapshot['max_amount_minor']) {
            $request->update(['status' => LoanRequestStatus::Rejected, 'rejected_reason' => 'recheck_failed: plafond dépassé']);
            throw new LoanRecheckFailedException(['Le plafond a diminué depuis l\'approbation.']);
        }

        return DB::transaction(function () use ($request, $membership, $disbursedById, $channel, $phone) {
            $reference = $this->nextReference();

            $loan = Loan::create([
                'reference'         => $reference,
                'loan_request_id'   => $request->id,
                'membership_id'     => $membership->id,
                'principal_minor'   => $request->amount_minor,
                'outstanding_minor' => $request->amount_minor,
                'status'            => LoanStatus::Active,
                'disbursed_channel' => $channel,
                'disbursed_phone'   => $phone,
                'disbursed_by'      => $disbursedById,
                'disbursed_at'      => now(),
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
                ownerId: $membership->user_id,
                description: "Avance {$reference} — {$membership->user?->full_name}",
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
                description: "Décaissement avance {$reference}",
                createdById: $disbursedById,
                metadata:    ['loan_id' => $loan->id],
            );

            // In dev: log the MoMo outbound transfer
            if ($channel !== 'cash') {
                Log::info("MoMo OUTBOUND [{$channel}] {$loan->reference} → {$phone} : {$request->amount_minor} XOF");
            }

            return $loan;
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

            $txnRef = $this->ledger->nextReference();
            $this->ledger->post(
                legs: [
                    ['account' => $floatKey,       'type' => 'debit',  'amount' => $effective],
                    ['account' => $receivableKey,   'type' => 'credit', 'amount' => $effective],
                ],
                reference:   $txnRef,
                description: "Remboursement avance {$loan->reference}",
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
