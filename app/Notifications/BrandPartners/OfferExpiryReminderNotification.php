<?php

namespace App\Notifications\BrandPartners;

use App\Models\BrandPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OfferExpiryReminderNotification extends Notification
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
            'notification_type' => 'brand_offer_expiry_reminder',
            'title' => '⏰ Only 2 Days Left',
            'body' => 'Your exclusive discount expires soon.',
            'partner_id' => $this->partner->id,
            'partner_name' => $this->partner->name,
            'offer_title' => $this->partner->offer_title,
            'valid_to' => $this->partner->valid_to ? $this->partner->valid_to->toIso8601String() : null,
        ];
    }
}
