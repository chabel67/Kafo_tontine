<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class PayoutAlreadySettledException extends BusinessException
{
    public function __construct(string $payoutId)
    {
        parent::__construct(
            "Le versement {$payoutId} n'est plus en attente : il ne peut être ni réglé ni annulé.",
            'payout_not_pending',
            409,
        );
    }
}
