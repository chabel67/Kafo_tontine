<?php

namespace App\Modules\Identity\Domain\Exceptions;

class InsufficientKycLevelException extends BusinessException
{
    public function __construct(int $required, int $actual)
    {
        parent::__construct(
            "KYC level {$required} required, current level is {$actual}.",
            'insufficient_kyc_level',
            403,
        );
    }
}
