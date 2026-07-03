<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpExpiredException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('The OTP code has expired.', 'otp_expired', 401);
    }
}
