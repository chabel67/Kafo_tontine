<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpRateLimitedException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Too many OTP requests. Please wait before trying again.', 'otp_rate_limited', 429);
    }
}
