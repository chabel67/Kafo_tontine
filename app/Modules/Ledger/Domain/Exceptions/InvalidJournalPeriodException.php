<?php

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/**
 * Levée quand la période demandée pour un journal des opérations
 * dépasse la borne max (90 jours par défaut) — R-LEDGER-08.
 */
class InvalidJournalPeriodException extends BusinessException
{
    public function __construct(int $maxDays, int $requestedDays)
    {
        parent::__construct(
            "La période demandée ({$requestedDays} jours) dépasse le maximum autorisé ({$maxDays} jours).",
            'invalid_journal_period',
            422,
        );
    }
}
