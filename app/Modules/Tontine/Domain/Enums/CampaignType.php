<?php

namespace App\Modules\Tontine\Domain\Enums;

/**
 * Type de campagne. `savings` : épargne individuelle réglée à la clôture (comportement
 * historique). `rotating` : caisse commune redistribuée tour par tour (tontine rotative).
 */
enum CampaignType: string
{
    case Savings  = 'savings';
    case Rotating = 'rotating';

    public function label(): string
    {
        return match ($this) {
            self::Savings  => 'Épargne',
            self::Rotating => 'Tontine rotative',
        };
    }
}
