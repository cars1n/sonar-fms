<?php

namespace App\Console;

use App\Console\Commands\SyncTenantSisData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(SyncTenantSisData::class)->hourly();
        $schedule->command('portal:sync')->everyMinute()->withoutOverlapping();
        $schedule->command('portal:sync --full')->hourly()->withoutOverlapping();

        $schedule->command('cache:prune-stale-tags')->hourly();

        $schedule->command('backup:clean')
            ->dailyAt('17:00');
        $schedule->command('backup:run', ['--only-db'])
            ->dailyAt('17:30'); // 1:30am China
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
