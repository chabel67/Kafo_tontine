<?php

namespace App\Modules\Identity\Domain\Exceptions;

class PinLockedException extends BusinessException
{
    public function __construct(public readonly int $lockSeconds = 60)
    {
        parent::__construct(
            "Compte temporairement bloqué après trop de tentatives. Réessayez dans {$lockSeconds} secondes.",
            'pin_locked',
            423,
        );
    }
}
