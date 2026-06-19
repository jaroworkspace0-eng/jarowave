<?php

use App\Jobs\ProcessAccountDeletions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessAccountDeletions)->dailyAt('00:00');
Schedule::command('echo:suspend-non-paying')->dailyAt('00:01');
Schedule::command('echo:suspend-non-paying-estates')->dailyAt('00:02');
Schedule::command('echo:send-payment-reminders')->dailyAt('08:00');