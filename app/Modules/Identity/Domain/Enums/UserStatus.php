<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserStatus: string
{
    /** Créé par le staff, pas encore activé : connexion et création de PIN bloquées. */
    case Pending   = 'pending';
    case Active    = 'active';
    case Suspended = 'suspended';
    case Closed    = 'closed';
}
