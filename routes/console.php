<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| Databases nightly, file volumes weekly. The asymmetry is deliberate: a
| database dump is megabytes and a media volume is not, and a nightly transfer
| of the latter is the difference between a policy that runs and one that gets
| turned off for using too much disk.
|
| Each command only queues work, so a multi-gigabyte dump never blocks the
| scheduler's sixty-second loop the way the old synchronous one did.
|
*/

Schedule::command('backup:control-plane')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backups.log'));

Schedule::command('backup:databases')
    ->dailyAt('02:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backups.log'));

Schedule::command('backup:files')
    ->weeklyOn(0, '03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backups.log'));

Schedule::command('backup:prune')
    ->dailyAt('04:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backups.log'));

/*
|--------------------------------------------------------------------------
| Drift
|--------------------------------------------------------------------------
|
| The one thing a cluster would do for us. Every five minutes, ask each server
| what it is actually running and compare digests with what we think.
|
*/

Schedule::command('stacks:poll-drift')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/drift.log'));

/*
|--------------------------------------------------------------------------
| Releases
|--------------------------------------------------------------------------
|
| A destructive release waits for the window agreed with the client, then
| starts on its own. Nothing else ever deploys without a click.
|
*/

Schedule::command('releases:run-due')
    ->everyMinute()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Monitoring
|--------------------------------------------------------------------------
|
| One SSH call per server per minute; nothing installed on the servers.
|
*/

Schedule::command('servers:collect-metrics')
    ->everyMinute()
    ->withoutOverlapping();
