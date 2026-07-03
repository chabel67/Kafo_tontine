<?php

namespace App\Modules\Lending\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class LoanIneligibleException extends BusinessException
{
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode('; ', $reasons), 'loan_ineligible', 422);
    }
}
