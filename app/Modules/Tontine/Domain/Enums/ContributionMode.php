<?php

namespace App\Modules\Tontine\Domain\Enums;

enum ContributionMode: string
{
    case Fixed  = 'fixed';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Fixed  => 'Cotisation fixe',
            self::Custom => 'Cotisation personnalisée',
        };
    }
}
