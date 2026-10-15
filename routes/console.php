<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('daily-contracts:refresh')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120);

Schedule::command('characters:release-expired-bans')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('pvp:expire-challenges')
    ->everyMinute()
    ->withoutOverlapping();
