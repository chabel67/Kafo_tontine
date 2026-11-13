<?php

namespace App\Modules\Identity\Domain\Exceptions;

/**
 * PIN refusé car trop simple (6 chiffres identiques, suite croissante/décroissante).
 * Même code `pin_weak` que POST /member/profile/pin.
 */
class PinWeakException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(PinWeakException::MESSAGE, 'pin_weak', 422);
    }

    public const MESSAGE = 'Le PIN doit contenir 6 chiffres et ne peut être ni une suite (123456, 654321…) ni un même chiffre répété.';
}
