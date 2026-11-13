<?php

namespace App\Modules\Identity\Domain\Exceptions;

class UnauthorizedException extends BusinessException
{
    public function __construct(string $permission = '')
    {
        $msg = $permission
            ? "Vous n'avez pas la permission « {$permission} »."
            : 'Vous n\'êtes pas autorisé à effectuer cette action.';

        parent::__construct($msg, 'unauthorized', 403);
    }
}
