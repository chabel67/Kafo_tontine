<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class CampaignAlreadyClosedException extends BusinessException
{
    public function __construct(string $campaignId)
    {
        parent::__construct(
            "Campaign {$campaignId} is already closed or cannot be closed from its current state.",
            'campaign_already_closed',
            409,
        );
    }
}
