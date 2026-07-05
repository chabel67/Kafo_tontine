<?php

namespace App\Modules\Ledger\Domain\Enums;

/**
 * Catégorie d'une opération manuelle du staff (R-LEDGER-11).
 * Enum fermé pour permettre l'agrégation en reporting.
 */
enum ManualEntryCategory: string
{
    case Rent             = 'rent';
    case Salary           = 'salary';
    case Supplies         = 'supplies';
    case Transport        = 'transport';
    case Communications   = 'communications';
    case Maintenance      = 'maintenance';
    case Printing         = 'printing';
    case DonationReceived = 'donation_received';
    case Subsidy          = 'subsidy';
    case Other            = 'other';

    public function label(): string
    {
        return match($this) {
            self::Rent             => 'Loyer',
            self::Salary           => 'Salaires',
            self::Supplies         => 'Fournitures',
            self::Transport        => 'Transport',
            self::Communications   => 'Communications',
            self::Maintenance      => 'Maintenance',
            self::Printing         => 'Impressions',
            self::DonationReceived => 'Don reçu',
            self::Subsidy          => 'Subvention',
            self::Other            => 'Autre',
        };
    }

    /**
     * Sens attendu par nature — utilisé pour pré-remplir l'UI.
     * Non bloquant côté API : le staff peut saisir n'importe quelle direction.
     */
    public function defaultDirection(): string
    {
        return match($this) {
            self::DonationReceived, self::Subsidy => 'in',
            default                                => 'out',
        };
    }
}
