<?php

namespace App\Modules\Payments\Domain\Enums;

enum PaymentStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'En attente',
            self::Confirmed => 'Confirmé',
            self::Failed    => 'Échoué',
            self::Cancelled => 'Annulé',
        };
    }
}
