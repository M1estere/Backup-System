<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:reap')->everyMinute()->withoutOverlapping();
Schedule::command('projects:sync-due')->everyMinute()->withoutOverlapping();
