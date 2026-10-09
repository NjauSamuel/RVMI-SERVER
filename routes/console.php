<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cron entry point on shared hosting:
//   * * * * * php /path/to/server/artisan schedule:run >> /dev/null 2>&1
Schedule::command('media:process-pending')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:dispatch-digest')->everyFiveMinutes()->withoutOverlapping();
