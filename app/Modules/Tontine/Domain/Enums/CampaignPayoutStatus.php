<?php

namespace App\Modules\Tontine\Domain\Enums;

enum CampaignPayoutStatus: string
{
    case Pending   = 'pending';
    case Settled   = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'À régler',
            self::Settled   => 'Réglé',
            self::Cancelled => 'Annulé',
        };
    }
}
