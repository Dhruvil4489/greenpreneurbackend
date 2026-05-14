<?php

namespace App\Http\Resources\Event;

use App\Services\Events\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'event_type' => $this->event_type,
            'event_category' => $this->event_category,
            'mode' => $this->mode,
            'circle' => $this->circle ? ['id' => $this->circle->id, 'name' => $this->circle->name, 'slug' => $this->circle->slug ?? null] : null,
            'start_at' => optional($this->start_at)->toISOString(),
            'end_at' => optional($this->end_at)->toISOString(),
            'location_text' => $this->location_text,
            'agenda' => $this->agenda,
            'speakers' => $this->speakers,
            'banner_url' => $this->banner_url,
            'visibility' => $this->visibility,
            'is_paid' => (bool) $this->is_paid,
            'ticket_price' => (string) ($this->ticket_price ?? '0.00'),
            'registration_limit' => $this->registration_limit,
            'qr_checkin_enabled' => (bool) $this->qr_checkin_enabled,
            'is_public' => (bool) $this->is_public,
            'recurrence' => [
                'type' => $this->recurrence_type,
                'interval' => $this->recurrence_interval,
                'day_of_week' => $this->recurrence_day_of_week,
                'week_of_month' => $this->recurrence_week_of_month,
                'day_of_month' => $this->recurrence_day_of_month,
                'month' => $this->recurrence_month,
                'ends_at' => optional($this->recurrence_ends_at)->toISOString(),
            ],
            'eligibility' => [
                'is_eligible' => app(EventService::class)->isEligible($this->resource, $request->user()),
                'reason' => app(EventService::class)->isEligible($this->resource, $request->user()) ? null : 'User is not eligible for this event.',
            ],
            'occurrences' => EventOccurrenceListResource::collection($this->whenLoaded('occurrences')),
        ];
    }
}
