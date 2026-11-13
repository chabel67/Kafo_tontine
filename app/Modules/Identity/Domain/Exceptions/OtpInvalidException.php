<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpInvalidException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Le code OTP est invalide ou a déjà été utilisé.', 'otp_invalid', 401);
    }
}
