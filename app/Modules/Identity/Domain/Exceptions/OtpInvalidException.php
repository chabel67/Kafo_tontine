<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpInvalidException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('The OTP code is invalid or has already been used.', 'otp_invalid', 401);
    }
}
