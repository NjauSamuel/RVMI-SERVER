<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One cPanel cron, every five minutes (Namecheap's minimum):
//   */5 * * * * php /path/to/server/artisan schedule:run >> /dev/null 2>&1
// Comments and uploads push immediately. This tick only retries media
// processing and flushes approval pushes that waited out 30 minutes.
Schedule::command('notifications:flush-approval-hold')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('media:process-pending')->everyFiveMinutes()->withoutOverlapping();
