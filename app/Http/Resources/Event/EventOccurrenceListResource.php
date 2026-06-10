<?php

namespace App\Http\Resources\Event;

use App\Models\User;
use App\Services\Events\EventQrService;
use App\Services\Events\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventOccurrenceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $event = $this->event;
        $registration = $this->registrations->first();
        $limit = $this->registration_limit ?? $event->registration_limit;
        $registeredCount = (int) ($this->registered_count ?? 0);
        $qr = app(EventQrService::class);
        $eventService = app(EventService::class);
        $unityUser = $request->user() instanceof User ? $request->user() : null;
        $canRegister = $eventService->canRegister($event, $unityUser);
        $showOnlineUrl = (bool) $registration || (bool) ($event->is_public ?? false) || $event->visibility === 'public';
        $metadata = is_string($event->metadata) ? json_decode($event->metadata, true) : $event->metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $zohoFormUrl = $event->zoho_form_url ?? data_get($metadata, 'zoho_form_url');
        $visitorRegistrationEnabled = $eventService->visitorRegistrationEnabled($event);

        return [
            'occurrence_id' => $this->id,
            'event_id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'event_type' => $event->event_type,
            'event_category' => $event->event_category,
            'mode' => $event->mode,
            'recurrence' => [
                'type' => $event->recurrence_type,
                'interval' => $event->recurrence_interval,
                'ends_at' => optional($event->recurrence_ends_at)->toISOString(),
            ],
            'circle' => $event->circle ? ['id' => $event->circle->id, 'name' => $event->circle->name, 'slug' => $event->circle->slug ?? null] : null,
            'start_at' => optional($this->start_at)->toISOString(),
            'end_at' => optional($this->end_at)->toISOString(),
            'status' => $this->status ?? $event->status ?? 'scheduled',
            'display_date' => optional($this->start_at)->format('M d, Y'),
            'display_time' => trim(optional($this->start_at)->format('h:i A').' - '.optional($this->end_at)->format('h:i A'), ' -'),
            'location_text' => $event->location_text,
            'location' => [
                'text' => $event->location_text,
                'venue_name' => $metadata['venue_name'] ?? null,
                'address_line' => $metadata['address_line'] ?? null,
                'city' => $metadata['city'] ?? null,
                'state' => $metadata['state'] ?? null,
                'google_maps_url' => $metadata['google_maps_url'] ?? null,
            ],
            'online_meeting_url' => $showOnlineUrl ? ($event->online_meeting_url ?? null) : null,
            'banner_url' => $event->banner_url,
            'agenda' => $event->agenda,
            'speakers' => $event->speakers,
            'what_youll_gain' => array_values((array) data_get($metadata, 'what_youll_gain', [])),
            'organizer' => data_get($metadata, 'organizer'),
            'is_paid' => (bool) $event->is_paid,
            'ticket_price' => (string) ($event->ticket_price ?? '0.00'),
            'registration_limit' => $limit,
            'registered_count' => $registeredCount,
            'checkin_count' => (int) ($this->checked_in_count ?? 0),
            'available_seats' => $limit ? max(0, $limit - $registeredCount) : null,
            'qr_checkin_enabled' => (bool) $event->qr_checkin_enabled,
            'can_register' => $canRegister['can_register'],
            'can_register_reason' => $canRegister['reason'],
            'visitor_registration_enabled' => $visitorRegistrationEnabled,
            'zoho_form_url' => $zohoFormUrl,
            'visitor_registration_url' => $visitorRegistrationEnabled ? url('/events/'.$event->id.'/occurrences/'.$this->id.'/visitor-register') : null,
            'zoho_visitor_registration_url' => $visitorRegistrationEnabled ? $zohoFormUrl : null,
            'member_registration_enabled' => $eventService->memberRegistrationEnabled($event),
            'user_registration' => [
                'is_registered' => (bool) $registration,
                'registration_id' => $registration?->id,
                'status' => $registration?->status,
                'checkin_status' => $registration?->checkin_status,
                'payment_gateway' => ($registration?->payment_required ?? false) ? ($registration->payment_gateway ?: (string) config('services.event_payment_gateway', 'zoho_billing_payment_link')) : null,
                'payment_gateway' => ($registration?->payment_required ?? false) ? (in_array(strtolower((string) ($registration->payment_gateway ?: config('services.event_payment_gateway', 'zoho_billing_payment_link'))), ['none', 'not_required', 'null', ''], true) ? 'zoho_billing_payment_link' : ($registration->payment_gateway ?: (string) config('services.event_payment_gateway', 'zoho_billing_payment_link'))) : null,
                'payment_status' => $registration?->payment_status,
                'razorpay_order_id' => $registration?->razorpay_order_id,
                'checkout_url' => $registration?->payment_url ?? $registration?->zoho_checkout_url ?? $registration?->zoho_payment_link_url ?? $registration?->zoho_hosted_page_url ?? null,
                'qr_code_url' => $registration ? ((($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid') ? null : ($registration->qr_code_path ? $qr->url($registration->qr_code_path) : $registration->qr_code_url)) : null,
            ],
        ];
    }
}
