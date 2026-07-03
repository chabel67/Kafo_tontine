<?php

namespace App\Modules\Tontine\Domain\Enums;

enum CadenceType: string
{
    case EveryNDays    = 'every_n_days';
    case NamedWeekdays = 'named_weekdays';
    case Monthly       = 'monthly';
}
