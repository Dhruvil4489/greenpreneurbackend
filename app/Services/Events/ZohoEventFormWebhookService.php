<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ZohoEventFormWebhookService
{
    public function __construct(private readonly EventRegistrationService $registrations) {}

    public function handle(array $payload): EventRegistration
    {
        Log::info('Zoho event form webhook received.', ['payload' => $payload]);

        $eventId = $this->value($payload, ['event_id', 'Event_ID', 'metadata.event_id', 'data.event_id']);
        $occurrenceId = $this->value($payload, ['occurrence_id', 'Occurrence_ID', 'metadata.occurrence_id', 'data.occurrence_id']);

        if (! $eventId || ! $occurrenceId) {
            throw ValidationException::withMessages(['event' => 'event_id and occurrence_id are required.']);
        }

        $event = Event::query()->find($eventId);
        $occurrence = EventOccurrence::query()->find($occurrenceId);
        if (! $event || ! $occurrence) {
            throw ValidationException::withMessages(['event' => 'Event or occurrence not found.']);
        }

        return $this->registrations->syncZohoVisitor($event, $occurrence, [
            'visitor_name' => (string) ($this->value($payload, ['visitor_name', 'Name', 'Full_Name', 'data.Name', 'data.Full_Name']) ?: 'Zoho Visitor'),
            'visitor_email' => $this->value($payload, ['visitor_email', 'Email', 'data.Email']),
            'visitor_phone' => $this->value($payload, ['visitor_phone', 'Phone', 'Mobile', 'data.Phone', 'data.Mobile']),
            'visitor_company' => $this->value($payload, ['visitor_company', 'Company', 'data.Company']),
            'visitor_city' => $this->value($payload, ['visitor_city', 'City', 'data.City']),
            'zoho_form_entry_id' => $this->value($payload, ['zoho_form_entry_id', 'record_id', 'ID', 'id', 'data.record_id', 'data.ID']),
            'zoho_payment_id' => $this->value($payload, ['zoho_payment_id', 'payment_id', 'Payment_ID', 'data.Payment_ID']),
            'zoho_payment_status' => $this->value($payload, ['zoho_payment_status', 'payment_status', 'Payment_Status', 'data.Payment_Status']),
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
