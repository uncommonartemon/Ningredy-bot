<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:health-check')->everyTwoMinutes();
Schedule::command('bot:guard')->everyMinute()->withoutOverlapping(10);
Schedule::command('gallery:recover-stuck-drafts')->everyFifteenMinutes();
Schedule::command('catalog:card-highlights')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('catalog:translate-names')->everyFiveMinutes()->withoutOverlapping(10);
// A card whose translation failed or never ran: queued again, the same texts at most once in six hours.
Schedule::command('catalog:translate-missing --due')->everyTenMinutes()->withoutOverlapping(10);
