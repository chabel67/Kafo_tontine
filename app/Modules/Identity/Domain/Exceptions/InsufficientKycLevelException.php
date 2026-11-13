<?php

namespace App\Modules\Identity\Domain\Exceptions;

class InsufficientKycLevelException extends BusinessException
{
    public function __construct(int $required, int $actual)
    {
        parent::__construct(
            "Niveau KYC {$required} requis (niveau actuel : {$actual}).",
            'insufficient_kyc_level',
            403,
        );
    }
}
