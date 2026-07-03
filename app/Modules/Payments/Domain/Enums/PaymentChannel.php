<?php

namespace App\Modules\Payments\Domain\Enums;

enum PaymentChannel: string
{
    case Cash   = 'cash';
    case Mtn    = 'mtn';
    case Orange = 'orange';
    case Moov   = 'moov';
    case Wave   = 'wave';

    public function label(): string
    {
        return match($this) {
            self::Cash   => 'Espèces',
            self::Mtn    => 'MTN MoMo',
            self::Orange => 'Orange Money',
            self::Moov   => 'Moov Money',
            self::Wave   => 'Wave',
        };
    }

    public function isMomo(): bool
    {
        return $this !== self::Cash;
    }
}
