<?php

namespace App\Http\Resources\Event;

use App\Models\User;
use App\Services\Events\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->normalizedMetadata($this->metadata);
        $zohoFormUrl = $this->zoho_form_url ?? data_get($metadata, 'zoho_form_url');
        $visitorRegistrationEnabled = app(EventService::class)->visitorRegistrationEnabled($this->resource);

        $event = [
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
            'location' => [
                'text' => $this->location_text,
                'venue_name' => $metadata['venue_name'] ?? null,
                'address_line' => $metadata['address_line'] ?? null,
                'city' => $metadata['city'] ?? null,
                'state' => $metadata['state'] ?? null,
                'google_maps_url' => $metadata['google_maps_url'] ?? null,
            ],
            'online_meeting_url' => $this->online_meeting_url ?? null,
            'agenda' => $this->agenda,
            'speakers' => $this->speakers,
            'banner_url' => $this->banner_url,
            'what_youll_gain' => array_values((array) data_get($metadata, 'what_youll_gain', [])),
            'organizer' => data_get($metadata, 'organizer'),
            'visibility' => $this->visibility,
            'is_paid' => (bool) $this->is_paid,
            'ticket_price' => (string) ($this->ticket_price ?? '0.00'),
            'registration_limit' => $this->registration_limit,
            'qr_checkin_enabled' => (bool) $this->qr_checkin_enabled,
            'is_public' => (bool) $this->is_public,
            'visitor_registration_enabled' => $visitorRegistrationEnabled,
            'zoho_form_url' => $zohoFormUrl,
            'visitor_registration_url' => $visitorRegistrationEnabled ? $zohoFormUrl : null,
            'member_registration_enabled' => app(EventService::class)->memberRegistrationEnabled($this->resource),
            'recurrence' => [
                'type' => $this->recurrence_type,
                'interval' => $this->recurrence_interval,
                'day_of_week' => $this->recurrence_day_of_week,
                'week_of_month' => $this->recurrence_week_of_month,
                'day_of_month' => $this->recurrence_day_of_month,
                'month' => $this->recurrence_month,
                'ends_at' => optional($this->recurrence_ends_at)->toISOString(),
            ],
        ];
        $unityUser = $request->user() instanceof User ? $request->user() : null;
        $eventService = app(EventService::class);
        $canRegister = $eventService->canRegister($this->resource, $unityUser);
        $isEligible = $eventService->isEligible($this->resource, $unityUser);

        return $event + [
            'event' => $event,
            'can_register' => $canRegister['can_register'],
            'can_register_reason' => $canRegister['reason'],
            'eligibility' => [
                'is_eligible' => $isEligible,
                'reason' => $isEligible ? null : 'User is not eligible for this event.',
            ],
            'occurrences' => EventOccurrenceListResource::collection($this->whenLoaded('occurrences')),
            'upcoming_occurrences' => EventOccurrenceListResource::collection($this->whenLoaded('occurrences')),
        ];
    }

    private function normalizedMetadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        return is_array($metadata) ? $metadata : [];
    }
}
