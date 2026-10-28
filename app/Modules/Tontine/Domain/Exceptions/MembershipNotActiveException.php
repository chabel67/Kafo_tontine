<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

class MembershipNotActiveException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(
            'Un retrait anticipé n\'est possible que sur une adhésion active.',
            'membership_not_active',
            422,
        );
    }
}
