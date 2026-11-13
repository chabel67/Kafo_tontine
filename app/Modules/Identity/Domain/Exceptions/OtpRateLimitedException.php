<?php

namespace App\Modules\Identity\Domain\Exceptions;

class OtpRateLimitedException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Trop de demandes de code OTP. Patientez avant de réessayer.', 'otp_rate_limited', 429);
    }
}
