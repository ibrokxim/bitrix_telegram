<?php

namespace App\Console;

use App\Console\Commands\RegisterBitrix24Webhook;
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
        Commands\SetTelegramWebhook::class,
        Commands\ClearProductCache::class,
        Commands\SyncBitrixUsers::class,
        RegisterBitrix24Webhook::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Синхронизация каждый час
        $schedule->command('bitrix:sync-users')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
