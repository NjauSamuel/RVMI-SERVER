<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One cPanel cron, every five minutes (Namecheap's minimum):
//   */5 * * * * php /path/to/server/artisan schedule:run >> /dev/null 2>&1
// Digest first so a slow media retry cannot skip pushes.
Schedule::command('notifications:dispatch-digest')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('media:process-pending')->everyFiveMinutes()->withoutOverlapping();
