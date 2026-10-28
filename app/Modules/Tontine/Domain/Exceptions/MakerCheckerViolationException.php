<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class MakerCheckerViolationException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(
            'La personne qui a approuvé le retrait ne peut pas le décaisser (maker/checker).',
            'maker_checker_violation',
            422,
        );
    }
}
