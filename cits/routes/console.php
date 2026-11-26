<?php


use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cits:cleanup-temp')->daily();
Schedule::command('cits:purge-auth-logs')->dailyAt('19:25'); // 03:25 Asia/Taipei; cron runs every 5 minutes
Schedule::command('cits:needinfo-remind')->dailyAt('01:30'); // 09:30 Asia/Taipei (app runs in UTC)

Schedule::command('cits:send-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
