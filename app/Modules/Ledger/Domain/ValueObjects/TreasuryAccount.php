<?php

namespace App\Modules\Ledger\Domain\ValueObjects;

use App\Modules\Ledger\Domain\Enums\AccountType;

/**
 * Compte de trésorerie associé à un moyen de paiement (R-LEDGER-10, DA-20) :
 * `CASH_BOX` pour les espèces, `PSP_FLOAT:{AGRÉGATEUR}` (MAJUSCULES) pour un agrégateur
 * (KKiaPay, FedaPay) — le solde réel est chez l'agrégateur.
 *
 * Seule source de la clé. Les anciens comptes `MOMO_FLOAT:{RÉSEAU}` (avant DA-20) restent
 * lisibles mais ne reçoivent plus d'écriture : `kafo:migrate-psp-treasury` transfère leur
 * solde vers `PSP_FLOAT:KKIAPAY`.
 */
final class TreasuryAccount
{
    public const PREFIXES = ['CASH_BOX', 'PSP_FLOAT:', 'MOMO_FLOAT:'];

    public static function key(string $channel): string
    {
        return strtolower($channel) === 'cash' ? 'CASH_BOX' : 'PSP_FLOAT:' . strtoupper($channel);
    }

    public static function type(string $channel): AccountType
    {
        return strtolower($channel) === 'cash' ? AccountType::CashBox : AccountType::PspFloat;
    }

    /** Clé d'un compte de trésorerie (espèces, agrégateur ou ancien float réseau). */
    public static function isTreasuryKey(string $key): bool
    {
        return $key === 'CASH_BOX' || str_starts_with($key, 'PSP_FLOAT:') || str_starts_with($key, 'MOMO_FLOAT:');
    }
}
