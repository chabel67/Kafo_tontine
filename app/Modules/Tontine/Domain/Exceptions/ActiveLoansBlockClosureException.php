<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class ActiveLoansBlockClosureException extends BusinessException
{
    public function __construct(int $count)
    {
        parent::__construct(
            "Cannot close campaign: {$count} active loan(s) must be settled first.",
            'active_loans_block_closure',
            422,
        );
    }
}
