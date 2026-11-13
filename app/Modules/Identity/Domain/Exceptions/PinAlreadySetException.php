<?php

namespace App\Modules\Identity\Domain\Exceptions;

/**
 * Levée par POST /auth/pin/set et POST /admin/auth/pin/set quand le compte a déjà
 * un PIN : la création initiale ne doit jamais permettre de remplacer un PIN
 * existant avec un simple OTP (le changement passe par l'ancien PIN).
 */
class PinAlreadySetException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(
            'Un code PIN est déjà défini pour ce compte. Connectez-vous avec votre PIN actuel.',
            'pin_already_set',
            409,
        );
    }
}
