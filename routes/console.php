<?php

use App\Modules\Lending\Jobs\MarkLateLoanRepaymentsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Marque `late` les items d'échéancier de prêts standards dépassés.
Schedule::job(new MarkLateLoanRepaymentsJob())->dailyAt('01:00');
