<?php

namespace App\Console;

use App\Console\Commands\LifeImpactBackfillCommand;
use App\Console\Commands\RetryIgnoredZohoWebhooks;
use App\Console\Commands\RetryZohoWebhook;
use App\Console\Commands\RetryZohoWebhooks;
use App\Console\Commands\LifeImpactRecalculateUsersCommand;
use App\Console\Commands\SyncPaidEventInvoices;
use App\Console\Commands\SyncPaidMembershipPayments;
use App\Console\Commands\SyncZohoSubscriptionPayment;
use App\Console\Commands\TestZohoConvertInvoice;
use App\Console\Commands\TestZohoCustomerPaymentWebhook;
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
        SyncPaidMembershipPayments::class,
        SyncZohoSubscriptionPayment::class,
        TestZohoConvertInvoice::class,
        TestZohoCustomerPaymentWebhook::class,
        RetryZohoWebhook::class,
        RetryZohoWebhooks::class,
        RetryIgnoredZohoWebhooks::class,
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
