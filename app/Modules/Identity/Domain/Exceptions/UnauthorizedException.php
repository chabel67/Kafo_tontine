<?php

namespace App\Modules\Identity\Domain\Exceptions;

class UnauthorizedException extends BusinessException
{
    public function __construct(string $permission = '')
    {
        $msg = $permission
            ? "You do not have the '{$permission}' permission."
            : 'You are not authorized to perform this action.';

        parent::__construct($msg, 'unauthorized', 403);
    }
}
