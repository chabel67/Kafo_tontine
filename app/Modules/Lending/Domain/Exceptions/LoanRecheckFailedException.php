<?php

namespace App\Modules\Lending\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class LoanRecheckFailedException extends BusinessException
{
    public function __construct(array $reasons)
    {
        parent::__construct('Re-vérification échouée : ' . implode('; ', $reasons), 'loan_recheck_failed', 422);
    }
}
