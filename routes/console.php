<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recurring expenses are also caught up whenever Expenses or the P&L are opened, so this only
// needs `php artisan schedule:run` in cron to keep them current between visits.
Schedule::command('expenses:generate-recurring')->dailyAt('00:10');

// Last month's business review for every business. The Monthly reviews page can also generate it
// on demand, so a missed run is never lost.
Schedule::command('finance:monthly-review')->monthlyOn(1, '06:00');

// Each business's balance sheet figures at the end of the day, so inventory, customer credit and
// cash can be compared over time. Opening the Finance pages also takes today's if it is missing.
Schedule::command('finance:snapshot')->dailyAt('23:55');
