<?php

namespace App\Modules\Identity\Domain\Exceptions;

class PinLockedException extends BusinessException
{
    public function __construct(public readonly int $lockSeconds = 60)
    {
        parent::__construct(
            "Account temporarily locked after too many failed attempts. Try again in {$lockSeconds} seconds.",
            'pin_locked',
            423,
        );
    }
}
