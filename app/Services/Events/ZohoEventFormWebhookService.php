<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ZohoEventFormWebhookService
{
    public function __construct(private readonly EventRegistrationService $registrations) {}

    public function handle(array $payload): ?EventRegistration
    {
        Log::info('Zoho event form webhook received.', [
            'event_id' => $this->value($payload, ['event_id', 'metadata.event_id', 'data.event_id']),
            'occurrence_id' => $this->value($payload, ['occurrence_id', 'metadata.occurrence_id', 'data.occurrence_id']),
            'zoho_form_entry_id' => $this->value($payload, ['zoho_form_entry_id', 'entry_id', 'id', 'data.id']),
        ]);

        $eventId = $this->value($payload, ['event_id', 'metadata.event_id', 'data.event_id']);
        $occurrenceId = $this->value($payload, ['occurrence_id', 'metadata.occurrence_id', 'data.occurrence_id']);

        if (! $eventId || ! $occurrenceId) {
            return null;
        }

        $event = Event::query()->find($eventId);
        $occurrence = EventOccurrence::query()->find($occurrenceId);
        if (! $event || ! $occurrence) {
            return null;
        }

        return $this->registrations->registerVisitor($event, $occurrence, [
            'visitor_name' => (string) ($this->value($payload, ['visitor_name', 'name', 'data.name']) ?: 'Zoho Visitor'),
            'visitor_email' => $this->value($payload, ['visitor_email', 'email', 'data.email']),
            'visitor_phone' => $this->value($payload, ['visitor_phone', 'phone', 'data.phone']),
            'visitor_company' => $this->value($payload, ['visitor_company', 'company', 'data.company']),
            'visitor_city' => $this->value($payload, ['visitor_city', 'city', 'data.city']),
            'zoho_form_entry_id' => $this->value($payload, ['zoho_form_entry_id', 'entry_id', 'id', 'data.id']),
            'zoho_payment_id' => $this->value($payload, ['zoho_payment_id', 'payment_id', 'data.payment_id']),
            'zoho_payment_status' => $this->value($payload, ['zoho_payment_status', 'payment_status', 'data.payment_status']),
            'source' => 'zoho_form',
            'metadata' => ['zoho_payload' => $payload],
        ]);
    }

    private function value(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
