<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class PayoutAlreadySettledException extends BusinessException
{
    public function __construct(string $payoutId)
    {
        parent::__construct(
            "Payout {$payoutId} is not pending and cannot be settled or cancelled.",
            'payout_not_pending',
            409,
        );
    }
}
