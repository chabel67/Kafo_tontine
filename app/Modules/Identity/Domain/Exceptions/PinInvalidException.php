<?php

namespace App\Modules\Identity\Domain\Exceptions;

class PinInvalidException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Le code PIN est incorrect.', 'pin_invalid', 401);
    }
}
