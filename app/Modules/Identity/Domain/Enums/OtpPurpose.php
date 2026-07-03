<?php

namespace App\Modules\Identity\Domain\Enums;

enum OtpPurpose: string
{
    case Signup      = 'signup';
    case Login       = 'login';
    case StepUp      = 'step_up';
    case PhoneChange = 'phone_change';
}
