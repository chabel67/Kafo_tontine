<?php

namespace App\Modules\Tontine\Domain\Enums;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case Paid    = 'paid';
    case Late    = 'late';
    case Waived  = 'waived';
}
