<?php

namespace App\Shared;

use Illuminate\Support\Facades\DB;

/**
 * Références métier préfixées (TXN-2026-000123…), tirées d'une séquence PostgreSQL.
 *
 * Remplace l'ancien `count() + 1`, qui donnait la même référence à deux opérations
 * concurrentes (violation d'unicité, opération perdue). Une séquence n'est jamais
 * annulée par un rollback : un numéro peut être sauté, jamais réutilisé.
 */
final class Reference
{
    public const TXN    = 'ref_txn_seq';
    public const PAY    = 'ref_pay_seq';
    public const LOAN   = 'ref_loan_seq';
    public const PAYOUT = 'ref_payout_seq';
    public const WDR    = 'ref_wdr_seq';

    public static function next(string $prefix, string $sequence): string
    {
        $number = (int) DB::scalar('SELECT nextval(?)', [$sequence]);

        return sprintf('%s-%d-%06d', $prefix, now()->year, $number);
    }
}
