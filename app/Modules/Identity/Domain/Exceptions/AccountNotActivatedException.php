<?php

namespace App\Modules\Identity\Domain\Exceptions;

class AccountNotActivatedException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(
            'Votre compte n\'est pas encore activé. Contactez votre agence Kafo.',
            'account_not_activated',
            403,
        );
    }
}
