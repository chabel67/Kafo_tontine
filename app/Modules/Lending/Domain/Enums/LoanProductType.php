<?php

namespace App\Modules\Lending\Domain\Enums;

/**
 * Discriminant polymorphe entre les deux produits de prêt :
 *
 * - `Advance` : avance sur épargne durant campagne — pas d'échéancier, pas
 *   d'éligibilité bloquante, compensée au payout de clôture.
 * - `Standard` : prêt hors campagne — échéancier configurable, campagne
 *   optionnelle, taux d'intérêt optionnel.
 */
enum LoanProductType: string
{
    case Advance  = 'advance';
    case Standard = 'standard';

    public function label(): string
    {
        return match($this) {
            self::Advance  => 'Avance sur épargne',
            self::Standard => 'Prêt hors campagne',
        };
    }

    public function requiresSchedule(): bool
    {
        return $this === self::Standard;
    }

    public function requiresCampaign(): bool
    {
        return $this === self::Advance;
    }
}
