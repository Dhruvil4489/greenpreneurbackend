<?php

namespace App\Notifications\BrandPartners;

use App\Models\BrandPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewOfferAddedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BrandPartner $partner
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'system';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => 'brand_offer_added',
            'title' => '🔥 Limited Time Offer',
            'body' => $this->partner->offer_title . ' Grab before expiry.',
            'partner_id' => $this->partner->id,
            'partner_name' => $this->partner->name,
            'offer_title' => $this->partner->offer_title,
            'coupon_code' => $this->partner->coupon_code,
        ];
    }
}
