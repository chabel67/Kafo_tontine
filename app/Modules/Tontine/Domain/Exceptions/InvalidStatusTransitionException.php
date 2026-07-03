<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class InvalidStatusTransitionException extends BusinessException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            "Cannot transition campaign from '{$from}' to '{$to}'.",
            'invalid_status_transition',
            422,
        );
    }
}
