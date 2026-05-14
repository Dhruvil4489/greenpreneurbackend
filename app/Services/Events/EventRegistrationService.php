<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventQrService $qr,
    ) {}

    public function registerMember(Event $event, EventOccurrence $occurrence, User $user, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->isEligible($event, $user)) {
            throw ValidationException::withMessages(['event_id' => 'You are not eligible to register for this event.']);
        }

        return $this->createRegistration($event, $occurrence, ['user_id' => $user->id, 'source' => $source]);
    }

    public function registerVisitor(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if ($event->event_type !== 'public_event' && ! $event->is_public) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is allowed only for public events.']);
        }

        return $this->createRegistration($event, $occurrence, $data + ['source' => 'zoho_form']);
    }

    public function qrDetails(EventRegistration $registration): array
    {
        return [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'occurrence_id' => $registration->occurrence_id,
            'qr_token' => $registration->qr_token,
            'qr_payload' => $this->qr->payload($registration->qr_token),
            'qr_code_url' => $this->qr->url($registration->qr_code_path),
            'qr_code_svg' => $registration->qr_code_svg,
            'status' => $registration->status,
            'checkin_status' => $registration->checkin_status,
        ];
    }

    private function createRegistration(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        return DB::transaction(function () use ($event, $occurrence, $data): EventRegistration {
            $this->assertCapacity($event, $occurrence);

            $query = EventRegistration::query()->where('occurrence_id', $occurrence->id)->where('status', '!=', 'cancelled');
            if (isset($data['user_id'])) {
                $query->where('user_id', $data['user_id']);
            } else {
                $query->where(function ($q) use ($data): void {
                    $matched = false;
                    if (! empty($data['zoho_form_entry_id'])) {
                        $q->orWhere('zoho_form_entry_id', $data['zoho_form_entry_id']);
                        $matched = true;
                    }
                    if (! empty($data['visitor_email'])) {
                        $q->orWhere('visitor_email', $data['visitor_email']);
                        $matched = true;
                    }
                    if (! empty($data['visitor_phone'])) {
                        $q->orWhere('visitor_phone', $data['visitor_phone']);
                        $matched = true;
                    }
                    if (! $matched) {
                        $q->whereRaw('1 = 0');
                    }
                });
            }

            if ($query->exists()) {
                throw ValidationException::withMessages(['registration' => 'Already registered for this event occurrence.']);
            }

            $registration = EventRegistration::query()->create(array_merge($data, [
                'event_id' => $event->id,
                'occurrence_id' => $occurrence->id,
                'qr_token' => $this->uniqueToken(),
                'status' => 'registered',
                'checkin_status' => 'pending',
                'registered_at' => now(),
            ]));

            $qr = $this->qr->generateAndStore($registration);
            $registration->forceFill(['qr_code_path' => $qr['path'], 'qr_code_svg' => $qr['svg']])->save();

            $this->notifySafely($registration);

            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        });
    }

    private function assertCapacity(Event $event, EventOccurrence $occurrence): void
    {
        if (! $event->registration_limit) {
            return;
        }

        $registered = EventRegistration::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->count();

        if ($registered >= $event->registration_limit) {
            throw ValidationException::withMessages(['registration_limit' => 'Registration limit has been reached.']);
        }
    }

    private function uniqueToken(): string
    {
        do {
            $token = $this->qr->generateToken();
        } while (EventRegistration::query()->where('qr_token', $token)->exists());

        return $token;
    }

    private function notifySafely(EventRegistration $registration): void
    {
        try {
            Log::info('Event registration notification queued placeholder.', ['event_registration_id' => $registration->id]);
        } catch (\Throwable $exception) {
            Log::error('Event registration notification failed.', ['event_registration_id' => $registration->id, 'error' => $exception->getMessage()]);
        }
    }
}
