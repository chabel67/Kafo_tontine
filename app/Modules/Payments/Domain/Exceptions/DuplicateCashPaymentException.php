<?php

namespace App\Modules\Payments\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;
use App\Modules\Payments\Infrastructure\Models\Payment;

/**
 * Levée lorsqu'un 2ᵉ encaissement cash est tenté pour la même membership
 * dans la même journée (R-PAY-08). Bypassable via flag `force=true`
 * sur le payload (R-PAY-09).
 *
 * Le Payment existant est exposé en public readonly pour que le render HTTP
 * puisse le sérialiser sous `error.details.existing_payment`.
 */
class DuplicateCashPaymentException extends BusinessException
{
    public function __construct(public readonly Payment $existing)
    {
        parent::__construct(
            "Un encaissement cash a déjà été fait pour ce membre aujourd'hui.",
            'duplicate_cash_payment_today',
            409,
        );
    }
}
