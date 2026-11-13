<?php

namespace App\Modules\Payments\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/**
 * Levée lorsqu'un encaissement cash n'est pas un multiple entier du montant
 * contractuel de l'adhésion (R-PAY-05). Bypassable via `confirm_atypical=true`
 * sur le payload ; le bypass est audité (`payment.cash.atypical_confirmed`).
 */
class AtypicalAmountException extends BusinessException
{
    public function __construct(
        public readonly int $expectedMinor,
        public readonly int $amountMinor,
    ) {
        parent::__construct(
            'Montant atypique : il ne correspond pas à un multiple entier de la cotisation prévue. Confirmez explicitement pour l\'enregistrer.',
            'atypical_amount',
            422,
        );
    }
}
