<?php

namespace App\Modules\Lending\Domain\Enums;

use Carbon\CarbonInterface;

/**
 * Périodicité de remboursement d'un prêt standard.
 * Custom = staff saisit les dates à la main (custom_due_dates).
 */
enum LoanPeriodicity: string
{
    case Weekly  = 'weekly';
    case Monthly = 'monthly';
    case Custom  = 'custom';

    public function label(): string
    {
        return match($this) {
            self::Weekly  => 'Hebdomadaire',
            self::Monthly => 'Mensuelle',
            self::Custom  => 'Personnalisée',
        };
    }

    /**
     * Renvoie la n-ième date d'échéance après [$first] pour un pas régulier.
     * Ne s'applique pas à `custom` (les dates sont fournies explicitement).
     */
    public function nextDueDate(CarbonInterface $first, int $index): CarbonInterface
    {
        return match($this) {
            self::Weekly  => $first->copy()->addWeeks($index),
            self::Monthly => $first->copy()->addMonthsNoOverflow($index),
            self::Custom  => throw new \LogicException('Custom periodicity uses explicit dates'),
        };
    }
}
