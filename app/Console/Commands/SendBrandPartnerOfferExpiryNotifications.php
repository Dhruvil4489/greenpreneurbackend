<?php

namespace App\Console\Commands;

use App\Models\BrandPartner;
use App\Models\User;
use App\Notifications\BrandPartners\OfferExpiryReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendBrandPartnerOfferExpiryNotifications extends Command
{
    protected $signature = 'PGU:brand-partner-expiry-alerts';
    protected $description = 'Send expiry warning notifications to users who saved brand partners with offers expiring in 2 days';

    public function handle(): int
    {
        $this->info('Starting Brand Partner Offer Expiry checks...');

        // Find active brand partners with valid_to between 48h and 24h from now (exactly 2 days remaining)
        $targetMin = Carbon::now()->addDays(2)->startOfDay();
        $targetMax = Carbon::now()->addDays(2)->endOfDay();

        $expiringPartners = BrandPartner::query()
            ->where('is_active', true)
            ->whereNotNull('offer_title')
            ->whereBetween('valid_to', [$targetMin, $targetMax])
            ->get();

        if ($expiringPartners->isEmpty()) {
            $this->info('No brand partner offers are expiring in 2 days.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . $expiringPartners->count() . ' partner offers expiring in 2 days.');

        foreach ($expiringPartners as $partner) {
            // Find users who bookmarked this partner
            $savedUserIds = DB::table('brand_partner_saved')
                ->where('brand_partner_id', $partner->id)
                ->pluck('user_id')
                ->toArray();

            if (empty($savedUserIds)) {
                $this->info('No users have bookmarked partner: ' . $partner->name);
                continue;
            }

            // Retrieve active users in chunks
            User::query()
                ->whereIn('id', $savedUserIds)
                ->where('membership_status', '!=', 'expired')
                ->chunk(100, function ($users) use ($partner) {
                    Notification::send($users, new OfferExpiryReminderNotification($partner));
                });

            $this->info('Dispatched expiry notifications to ' . count($savedUserIds) . ' bookmarking users for: ' . $partner->name);
        }

        $this->info('Expiry alert checks completed successfully!');
        return Command::SUCCESS;
    }
}
