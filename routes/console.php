<?php

use App\Modules\Lending\Jobs\MarkLateLoanRepaymentsJob;
use App\Modules\Tontine\Jobs\MarkLateInstallmentsJob;
use App\Modules\Tontine\Jobs\RotationRemindersJob;
use App\Shared\BusinessTime;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Marque `late` les items d'échéancier de prêts standards dépassés.
// 01:00 heure locale métier (et non UTC) : le job raisonne sur le jour local.
Schedule::job(new MarkLateLoanRepaymentsJob())->dailyAt('01:00')->timezone(BusinessTime::timezone());

// B-03 / R-SCHED-03 : marque `late` les échéances de cotisation dont la grâce est dépassée.
Schedule::job(new MarkLateInstallmentsJob())->dailyAt('01:15')->timezone(BusinessTime::timezone());

// Lot 8 : rappels des tontines rotatives (cotisation demain, tour J-2, tour du jour prêt / bloqué).
Schedule::job(new RotationRemindersJob())->dailyAt('07:00')->timezone(BusinessTime::timezone());
