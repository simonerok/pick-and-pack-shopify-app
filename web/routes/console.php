<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

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

/*
|--------------------------------------------------------------------------
| Telescope prune (retention)
|--------------------------------------------------------------------------
| Delete Telescope entries older than config('telescope.prune_hours') so
| data is kept for about 2 weeks.
*/
Schedule::command('telescope:prune', [
    '--hours' => config('telescope.prune_hours', 720),
])->daily();

/*
|--------------------------------------------------------------------------
| Spatie Activity Log cleanup
|--------------------------------------------------------------------------
|
| Delete activity log records older than config('activitylog.delete_records_older_than_days').
| Retention is set in config/activitylog.php set to 2 years by default.
| --force skips confirmation when run by the scheduler.
|
*/
Schedule::command('activitylog:clean', ['--force'])->daily();

/*
|--------------------------------------------------------------------------
| Shopify sessions cleanup
|--------------------------------------------------------------------------
|
| Delete expired Shopify sessions (online tokens) and sessions not updated
| in 30 days. Keeps the sessions table from growing.
|
*/
Schedule::call(function () {
    $now = now();
    $thirtyDaysAgo = $now->copy()->subDays(30);

    // Expired online sessions (have expires_at in the past)
    \App\Models\Session::whereNotNull('expires_at')
        ->where('expires_at', '<', $now)
        ->delete();

    // Sessions not updated in 30 days (stale)
    \App\Models\Session::where('updated_at', '<', $thirtyDaysAgo)
        ->delete();
})->daily();

/*
|--------------------------------------------------------------------------
| Failed jobs cleanup
|--------------------------------------------------------------------------
|
| Delete failed job records older than 30 days. Horizon trims its Redis
| list; this prunes the database failed_jobs table.
|
*/
Schedule::call(function () {
    DB::table('failed_jobs')
        ->where('failed_at', '<', now()->subDays(30))
        ->delete();
})->daily();
