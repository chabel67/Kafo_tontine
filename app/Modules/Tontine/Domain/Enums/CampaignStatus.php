<?php

namespace App\Modules\Tontine\Domain\Enums;

enum CampaignStatus: string
{
    case Draft  = 'draft';
    case Open   = 'open';
    case Active = 'active';
    case Closed = 'closed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft  => $next === self::Open,
            self::Open   => $next === self::Active || $next === self::Draft,
            self::Active => $next === self::Closed,
            self::Closed => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft  => 'Brouillon',
            self::Open   => 'Ouverte',
            self::Active => 'Active',
            self::Closed => 'Clôturée',
        };
    }
}
