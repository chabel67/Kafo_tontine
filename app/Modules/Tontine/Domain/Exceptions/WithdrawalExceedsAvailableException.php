<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class WithdrawalExceedsAvailableException extends BusinessException
{
    public function __construct(int $requested, int $available)
    {
        parent::__construct(
            "Montant demandé ({$requested} XOF) supérieur à l'épargne disponible ({$available} XOF).",
            'withdrawal_exceeds_available',
            422,
        );
    }
}
