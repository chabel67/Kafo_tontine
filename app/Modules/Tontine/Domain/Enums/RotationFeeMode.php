<?php

namespace App\Modules\Tontine\Domain\Enums;

/** D-R5 : frais d'une campagne rotative (une seule option par campagne). */
enum RotationFeeMode: string
{
    case None                  = 'none';
    case PayoutDeduction       = 'payout_deduction';        // montant fixe retenu sur chaque cagnotte
    case ContributionSurcharge = 'contribution_surcharge';  // complément fixe ajouté à chaque cotisation

    public function label(): string
    {
        return match ($this) {
            self::None                  => 'Aucun frais',
            self::PayoutDeduction       => 'Retenue sur la cagnotte',
            self::ContributionSurcharge => 'Complément à chaque cotisation',
        };
    }
}
