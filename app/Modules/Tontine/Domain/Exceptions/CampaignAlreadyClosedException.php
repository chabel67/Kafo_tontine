<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class CampaignAlreadyClosedException extends BusinessException
{
    public function __construct(string $campaignId)
    {
        parent::__construct(
            "La campagne {$campaignId} est déjà clôturée ou ne peut pas l'être depuis son état actuel.",
            'campaign_already_closed',
            409,
        );
    }
}
