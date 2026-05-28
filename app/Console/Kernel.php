<?php

namespace App\Console;

use App\Console\Commands\LifeImpactBackfillCommand;
use App\Console\Commands\RetryZohoWebhooks;
use App\Console\Commands\LifeImpactRecalculateUsersCommand;
use App\Console\Commands\SyncPaidEventInvoices;
use App\Console\Commands\TestZohoConvertInvoice;
use App\Console\Commands\TestZohoPaidWebhook;
use App\Console\Commands\SendAppUpdateReminderNotifications;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        LifeImpactBackfillCommand::class,
        LifeImpactRecalculateUsersCommand::class,
        SendAppUpdateReminderNotifications::class,
        SyncPaidEventInvoices::class,
        TestZohoConvertInvoice::class,
        RetryZohoWebhooks::class,
        TestZohoPaidWebhook::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('collaborations:expire')->dailyAt('00:10');
        $schedule->command('memberships:expire-users')->hourly();
        $schedule->command('users:expire-trial')->hourly();
        $schedule->command('connections:send-pending-reminders')->dailyAt('09:00');
        $schedule->command('members:mark-offline-stale')->everyMinute();
    }
}
