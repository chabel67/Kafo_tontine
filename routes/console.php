<?php

use App\Modules\Lending\Jobs\MarkLateLoanRepaymentsJob;
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
