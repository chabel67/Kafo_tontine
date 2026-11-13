<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Concurrence (harmonisation 2026-11-13) :
 *  - les références métier (TXN, PAY, LOAN, PAYOUT, WDR) étaient calculées par
 *    `count() + 1` : deux opérations simultanées obtenaient la même référence.
 *    Elles sont désormais tirées de séquences PostgreSQL (App\Shared\Reference),
 *    initialisées au-delà du plus grand numéro déjà émis ;
 *  - une transaction ledger ne peut être contre-passée qu'une seule fois
 *    (index unique partiel sur `reverses_id`).
 */
return new class extends Migration
{
    private const SEQUENCES = [
        'ref_txn_seq'    => 'ledger_transactions',
        'ref_pay_seq'    => 'payments',
        'ref_loan_seq'   => 'loans',
        'ref_payout_seq' => 'campaign_payouts',
        'ref_wdr_seq'    => 'early_withdrawals',
    ];

    public function up(): void
    {
        foreach (self::SEQUENCES as $sequence => $table) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} START 1");
            $max = (int) DB::scalar(
                "SELECT COALESCE(MAX(CAST(substring(reference from '([0-9]+)\$') AS bigint)), 0) FROM {$table}"
            );
            if ($max > 0) {
                DB::statement("SELECT setval('{$sequence}', {$max}, true)");
            }
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS ledger_transactions_reverses_id_unique
             ON ledger_transactions (reverses_id) WHERE reverses_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_transactions_reverses_id_unique');
        foreach (array_keys(self::SEQUENCES) as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }
    }
};
