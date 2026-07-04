<?php

namespace App\Modules\Lending\Domain\Enums;

enum RepaymentScheduleStatus: string
{
    case Pending = 'pending';
    case Paid    = 'paid';
    case Late    = 'late';
    case Waived  = 'waived';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'À venir',
            self::Paid    => 'Payée',
            self::Late    => 'En retard',
            self::Waived  => 'Annulée',
        };
    }

    /** L'item peut recevoir un paiement (état non terminal). */
    public function isPayable(): bool
    {
        return $this === self::Pending || $this === self::Late;
    }
}
