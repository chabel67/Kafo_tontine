<?php

namespace App\Modules\Identity\Domain\Enums;

enum KycStatus: string
{
    case None     = 'none';
    case Pending  = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
