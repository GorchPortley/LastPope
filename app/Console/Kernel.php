<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('subscriptions:cancel-expired')->hourly();

        // Complete shipped orders after marketplace.auto_complete_days and release due payouts (physical orders
        // are paid out physical_payout_delay_days after shipping), retrying any transfer that failed.
        $schedule->command('marketplace:auto-complete')->hourly()->withoutOverlapping();

        // Cancel abandoned pending orders and retry failed automatic refunds.
        $schedule->command('marketplace:cleanup-orders')->hourly()->withoutOverlapping();

        // Read the suppliers' stock feeds into the dropshipped store listings (docs/PLAN.md 8.4).
        $schedule->command('marketplace:sync-supplier-stock')->hourly()->withoutOverlapping();

        // Mirror Stripe's books (balance transactions, payouts, balances) of the platform and of every seller's account
        // (docs/PLAN.md 11, R6), and compare them with the site's records once a day; new differences are mailed to the admins.
        $schedule->command('stripe:ledger-sync --all --queue')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('stripe:reconcile')->dailyAt('04:17')->withoutOverlapping();
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
