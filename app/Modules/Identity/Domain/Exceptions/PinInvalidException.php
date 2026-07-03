<?php

namespace App\Modules\Identity\Domain\Exceptions;

class PinInvalidException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('The PIN is incorrect.', 'pin_invalid', 401);
    }
}
