<?php

namespace App\Http\Resources\Event;

use App\Services\Events\EventQrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventOccurrenceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $event = $this->event;
        $registration = $this->registrations->first();
        $limit = $event->registration_limit;
        $registeredCount = (int) ($this->registered_count ?? 0);
        $qr = app(EventQrService::class);

        return [
            'occurrence_id' => $this->id,
            'event_id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'event_type' => $event->event_type,
            'event_category' => $event->event_category,
            'mode' => $event->mode,
            'circle' => $event->circle ? ['id' => $event->circle->id, 'name' => $event->circle->name, 'slug' => $event->circle->slug ?? null] : null,
            'start_at' => optional($this->start_at)->toISOString(),
            'end_at' => optional($this->end_at)->toISOString(),
            'location_text' => $event->location_text,
            'is_paid' => (bool) $event->is_paid,
            'ticket_price' => (string) ($event->ticket_price ?? '0.00'),
            'registration_limit' => $limit,
            'registered_count' => $registeredCount,
            'available_seats' => $limit ? max(0, $limit - $registeredCount) : null,
            'qr_checkin_enabled' => (bool) $event->qr_checkin_enabled,
            'user_registration' => [
                'is_registered' => (bool) $registration,
                'registration_id' => $registration?->id,
                'status' => $registration?->status,
                'checkin_status' => $registration?->checkin_status,
                'qr_code_url' => $registration ? $qr->url($registration->qr_code_path) : null,
            ],
        ];
    }
}
