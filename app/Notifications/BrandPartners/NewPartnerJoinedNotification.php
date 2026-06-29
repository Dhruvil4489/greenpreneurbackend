<?php

namespace App\Notifications\BrandPartners;

use App\Models\BrandPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewPartnerJoinedNotification extends Notification
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
        // Safe fallback to 'system' or 'activity_update' which are standard in the database enum
        return 'system';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => 'brand_partner_joined',
            'title' => '🎉 New Brand Partner Joined',
            'body' => 'Welcome ' . $this->partner->name . '! Enjoy exclusive benefits now.',
            'partner_id' => $this->partner->id,
            'partner_name' => $this->partner->name,
            'partner_slug' => $this->partner->slug,
            'logo_url' => $this->partner->logo_url,
        ];
    }
}
