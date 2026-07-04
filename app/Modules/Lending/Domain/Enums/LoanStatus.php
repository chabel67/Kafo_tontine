<?php

namespace App\Modules\Lending\Domain\Enums;

enum LoanStatus: string
{
    case Active            = 'active';
    case Repaid            = 'repaid';
    case WrittenOff        = 'written_off';
    case ClosedByCampaign  = 'closed_by_campaign';

    public function label(): string
    {
        return match($this) {
            self::Active           => 'Actif',
            self::Repaid           => 'Remboursé',
            self::WrittenOff       => 'Passé en perte',
            self::ClosedByCampaign => 'Soldé à la clôture',
        };
    }
}
