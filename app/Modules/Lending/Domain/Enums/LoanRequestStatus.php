<?php

namespace App\Modules\Lending\Domain\Enums;

enum LoanRequestStatus: string
{
    case Pending       = 'pending';
    case Approved      = 'approved';       // L1 approved, amount < threshold
    case Countersigning = 'countersigning'; // L1 approved, waiting for L2
    case Countersigned = 'countersigned';  // L2 done, ready to disburse
    case Disbursed     = 'disbursed';
    case Rejected      = 'rejected';
    case Cancelled     = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending        => 'En attente',
            self::Approved       => 'Approuvé',
            self::Countersigning => 'Contre-signature requise',
            self::Countersigned  => 'Contre-signé',
            self::Disbursed      => 'Décaissé',
            self::Rejected       => 'Refusé',
            self::Cancelled      => 'Annulé',
        };
    }

    public function isDisbursable(): bool
    {
        return in_array($this, [self::Approved, self::Countersigned]);
    }
}
