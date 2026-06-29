<?php

namespace App\Jobs;

use App\Models\BrandPartner;
use App\Models\User;
use App\Notifications\BrandPartners\NewPartnerJoinedNotification;
use App\Notifications\BrandPartners\NewOfferAddedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class SendBulkPartnerNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected BrandPartner $partner,
        protected string $notificationType // 'joined' or 'offer'
    ) {
    }

    public function handle(): void
    {
        $notification = $this->notificationType === 'joined'
            ? new NewPartnerJoinedNotification($this->partner)
            : new NewOfferAddedNotification($this->partner);

        // Notify active or visitor status members in chunks of 100
        User::query()
            ->where('membership_status', '!=', 'expired')
            ->chunk(100, function ($users) use ($notification) {
                Notification::send($users, $notification);
            });
    }
}
