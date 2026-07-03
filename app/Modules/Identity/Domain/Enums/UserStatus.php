<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Closed    = 'closed';
}
