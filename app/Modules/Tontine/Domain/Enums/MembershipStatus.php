<?php

namespace App\Modules\Tontine\Domain\Enums;

enum MembershipStatus: string
{
    case PendingL1  = 'pending_l1';
    case PendingL2  = 'pending_l2';
    case Active     = 'active';
    case Suspended  = 'suspended';
    case Closed     = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PendingL1  => 'En attente (L1)',
            self::PendingL2  => 'En attente (L2)',
            self::Active     => 'Active',
            self::Suspended  => 'Suspendue',
            self::Closed     => 'Clôturée',
        };
    }
}
