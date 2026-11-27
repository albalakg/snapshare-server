<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('event:start')->everyMinute();
        $schedule->command('event:end')->everyFiveMinutes();
        $schedule->command('event:notification-pending-1-day')->dailyAt('16:30');
        $schedule->command('event:notification-1-day')->dailyAt('17:00');
        $schedule->command('event:notification-5-days')->dailyAt('17:30');
        $schedule->command('event:warning')->dailyAt('18:00');
        $schedule->command('whatsapp:dispatch-scheduled')->everyMinute();
        $schedule->command('whatsapp-messaging:tick')->everyMinute()->withoutOverlapping();
        $schedule->command('icebreaker:purge')->hourly();
        $schedule->command('trivia:tick')->everyMinute();
        // Retention (Gal t142u): Classic 180 days / Premium 365 days after the event (subscriptions.storage_time).
        $schedule->command('event:disable')->dailyAt('20:00')->withoutOverlapping();
        // Emails v2 (t141u): deferred (quiet hours / Shabbat) sends, daily digests/nudges, 1h heads-up before scheduled sends.
        $schedule->command('emails:send-deferred')->everyMinute()->withoutOverlapping();
        $schedule->command('emails:daily')->dailyAt((string) config('emails.triggers.digest_hour', '09:00'))->timezone('Asia/Jerusalem')->withoutOverlapping();
        $schedule->command('emails:heads-up')->everyFiveMinutes()->withoutOverlapping();
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
