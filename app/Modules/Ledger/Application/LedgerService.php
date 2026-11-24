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

    /** Référence `TXN-…` d'une transaction (documents, R-DOC-01). */
    public function transactionReference(?string $transactionId): ?string
    {
        return $transactionId ? LedgerTransaction::whereKey($transactionId)->value('reference') : null;
    }

    /**
     * Référence de la première transaction (non contre-passée) dont `metadata[$key] = $value`
     * et qui ne porte aucune des clés `$withoutKeys`. Ex. décaissement d'un prêt : `loan_id`
     * sans `repayment_id` (le prêt ne stocke pas l'identifiant de sa transaction).
     */
    public function firstReferenceByMetadata(string $key, string $value, array $withoutKeys = []): ?string
    {
        $query = LedgerTransaction::whereRaw('metadata ->> ? = ?', [$key, $value])
            ->whereNull('reverses_id');
        foreach ($withoutKeys as $without) {
            $query->whereRaw('NOT (metadata ?? ?)', [$without]);
        }

        return $query->orderBy('created_at')->value('reference');
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

        return $account->type->isDebitNormal()
            ? (int)($debits - $credits)
            : (int)($credits - $debits);
    }

    /**
     * Balances of several accounts in one grouped query (same sign convention as balance()).
     * Unknown keys are returned with a 0 balance.
     *
     * @param  string[]  $accountKeys
     * @return array<string, int>
     */
    public function balances(array $accountKeys): array
    {
        $result = array_fill_keys($accountKeys, 0);
        if (empty($accountKeys)) {
            return $result;
        }

        $types = LedgerAccount::whereIn('key', $accountKeys)->pluck('type', 'key');

        $sums = LedgerEntry::whereIn('account_key', $accountKeys)
            ->selectRaw("account_key,
                SUM(CASE WHEN entry_type = 'credit' THEN amount_minor ELSE 0 END) as credits,
                SUM(CASE WHEN entry_type = 'debit'  THEN amount_minor ELSE 0 END) as debits")
            ->groupBy('account_key')
            ->get();

        foreach ($sums as $row) {
            $type = $types[$row->account_key] ?? null;
            $result[$row->account_key] = $type?->isDebitNormal()
                ? (int) ($row->debits - $row->credits)
                : (int) ($row->credits - $row->debits);
        }

        return $result;
    }

    /**
     * Generates the next unique transaction reference (TXN-YYYY-NNNNNN).
     */
    public function nextReference(): string
    {
        return \App\Shared\Reference::next('TXN', \App\Shared\Reference::TXN);
    }

    /**
     * Posts a reversal (counter-entry) for an existing transaction.
     */
    public function reverse(
        LedgerTransaction $original,
        string $description,
        ?string $createdById = null,
    ): LedgerTransaction {
        if (LedgerTransaction::where('reverses_id', $original->id)->exists()) {
            throw new \App\Modules\Ledger\Domain\Exceptions\TransactionAlreadyReversedException($original->reference);
        }

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
