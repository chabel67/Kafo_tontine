<?php

namespace App\Modules\Ledger\Domain\ValueObjects;

use App\Modules\Ledger\Domain\Enums\AccountType;

/**
 * Compte de trésorerie associé à un canal (R-LEDGER-10) : `CASH_BOX` pour les espèces,
 * `MOMO_FLOAT:{CANAL}` (toujours en MAJUSCULES) pour le Mobile Money.
 *
 * Seule source de la clé : avant 2026-11-13, chaque flux composait la sienne
 * (majuscules, minuscules ou brute), ce qui pouvait créer deux comptes pour un même canal.
 */
final class TreasuryAccount
{
    public static function key(string $channel): string
    {
        return strtolower($channel) === 'cash' ? 'CASH_BOX' : 'MOMO_FLOAT:' . strtoupper($channel);
    }

    public static function type(string $channel): AccountType
    {
        return strtolower($channel) === 'cash' ? AccountType::CashBox : AccountType::MomoFloat;
    }
}
