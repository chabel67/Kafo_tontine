<?php

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Opens a ledger account (idempotent — no-op if key already exists).
     */
    public function openAccount(
        string $key,
        AccountType $type,
        ?string $ownerId = null,
        ?string $description = null,
    ): LedgerAccount {
        return LedgerAccount::firstOrCreate(
            ['key' => $key],
            ['type' => $type->value, 'owner_id' => $ownerId, 'description' => $description],
        );
    }

    /**
     * Posts a balanced double-entry transaction.
     *
     * $legs = [
     *   ['account' => 'CASH_BOX', 'type' => 'debit',  'amount' => 5000],
     *   ['account' => 'MEMBER_SAVINGS:uuid', 'type' => 'credit', 'amount' => 5000],
     * ]
     *
     * Throws if sum(debits) ≠ sum(credits) or any account key doesn't exist.
     */
    public function post(
        array $legs,
        string $reference,
        string $description,
        ?string $createdById = null,
        array $metadata = [],
        ?string $reversesId = null,
    ): LedgerTransaction {
        // Validate balance
        $totalDebit  = array_sum(array_column(array_filter($legs, fn ($l) => $l['type'] === 'debit'),  'amount'));
        $totalCredit = array_sum(array_column(array_filter($legs, fn ($l) => $l['type'] === 'credit'), 'amount'));

        if ($totalDebit !== $totalCredit) {
            throw new \LogicException("Ledger transaction is unbalanced: debits={$totalDebit} credits={$totalCredit}");
        }

        if ($totalDebit === 0) {
            throw new \LogicException('Ledger transaction cannot have zero amount.');
        }

        // Validate all account keys exist
        $keys = array_unique(array_column($legs, 'account'));
        $found = LedgerAccount::whereIn('key', $keys)->pluck('key')->all();
        $missing = array_diff($keys, $found);
        if ($missing) {
            throw new \LogicException('Unknown ledger account keys: ' . implode(', ', $missing));
        }

        return DB::transaction(function () use ($legs, $reference, $description, $createdById, $metadata, $reversesId) {
            $txn = LedgerTransaction::create([
                'reference'   => $reference,
                'description' => $description,
                'reverses_id' => $reversesId,
                'metadata'    => $metadata ?: null,
                'created_by'  => $createdById,
                'created_at'  => now(),
            ]);

            foreach ($legs as $leg) {
                LedgerEntry::create([
                    'transaction_id' => $txn->id,
                    'account_key'    => $leg['account'],
                    'entry_type'     => $leg['type'],
                    'amount_minor'   => $leg['amount'],
                    'created_at'     => now(),
                ]);
            }

            return $txn;
        });
    }

    /**
     * Returns the running balance of an account in minor units.
     * Convention: credit balance (credits - debits) for savings/liability accounts.
     * For asset accounts (CASH_BOX, MOMO_FLOAT, LOAN_RECEIVABLE): debit balance (debits - credits).
     */
    public function balance(string $accountKey): int
    {
        $account = LedgerAccount::where('key', $accountKey)->firstOrFail();

        $credits = LedgerEntry::where('account_key', $accountKey)->where('entry_type', 'credit')->sum('amount_minor');
        $debits  = LedgerEntry::where('account_key', $accountKey)->where('entry_type', 'debit')->sum('amount_minor');

        return $account->type->isAsset()
            ? (int)($debits - $credits)
            : (int)($credits - $debits);
    }

    /**
     * Generates the next unique transaction reference (TXN-YYYY-NNNNNN).
     */
    public function nextReference(): string
    {
        $year  = now()->year;
        $count = LedgerTransaction::whereYear('created_at', $year)->count() + 1;
        return sprintf('TXN-%d-%06d', $year, $count);
    }

    /**
     * Posts a reversal (counter-entry) for an existing transaction.
     */
    public function reverse(
        LedgerTransaction $original,
        string $description,
        ?string $createdById = null,
    ): LedgerTransaction {
        $legs = $original->entries->map(fn (LedgerEntry $e) => [
            'account' => $e->account_key,
            'type'    => $e->entry_type === 'debit' ? 'credit' : 'debit',
            'amount'  => $e->amount_minor,
        ])->all();

        return $this->post(
            $legs,
            $this->nextReference(),
            $description,
            $createdById,
            ['reversal_of' => $original->reference],
            $original->id,
        );
    }
}
