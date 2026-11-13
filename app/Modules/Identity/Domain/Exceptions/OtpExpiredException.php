<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpExpiredException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Le code OTP a expiré ou n\'existe pas. Demandez un nouveau code.', 'otp_expired', 401);
    }
}
