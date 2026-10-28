<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class InvalidWithdrawalTransitionException extends BusinessException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            "Transition impossible du retrait : '{$from}' → '{$to}'.",
            'invalid_withdrawal_transition',
            422,
        );
    }
}
