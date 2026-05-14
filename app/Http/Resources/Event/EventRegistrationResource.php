<?php

namespace App\Http\Resources\Event;

use App\Services\Events\EventQrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $qr = app(EventQrService::class);

        return [
            'registration_id' => $this->id,
            'event_id' => $this->event_id,
            'occurrence_id' => $this->occurrence_id,
            'status' => $this->status,
            'checkin_status' => $this->checkin_status,
            'registered_at' => optional($this->registered_at)->toISOString(),
            'checked_in_at' => optional($this->checked_in_at)->toISOString(),
            'source' => $this->source,
            'qr_code_url' => $qr->url($this->qr_code_path),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'event_type' => $this->event->event_type,
                'mode' => $this->event->mode,
                'location_text' => $this->event->location_text,
            ]),
            'occurrence' => $this->whenLoaded('occurrence', fn () => [
                'id' => $this->occurrence->id,
                'start_at' => optional($this->occurrence->start_at)->toISOString(),
                'end_at' => optional($this->occurrence->end_at)->toISOString(),
            ]),
            'attendee' => $this->user ? [
                'type' => 'member',
                'id' => $this->user->id,
                'name' => $this->user->display_name ?? trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? '')),
                'email' => $this->user->email,
            ] : [
                'type' => 'visitor',
                'name' => $this->visitor_name,
                'email' => $this->visitor_email,
                'phone' => $this->visitor_phone,
                'company' => $this->visitor_company,
                'city' => $this->visitor_city,
            ],
        ];
    }
}
