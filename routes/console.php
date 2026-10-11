<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Accreditation reminders (30 days before the end, and once it has ended). Needs the server's cron:
// * * * * * cd /path/to/jvonlinebackend && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('accreditations:remind')->dailyAt('08:00')->timezone('Asia/Manila');
