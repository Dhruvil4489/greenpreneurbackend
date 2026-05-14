<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventCheckinService
{
    public function __construct(private readonly EventService $events) {}

    public function scan(string $qrToken, User $scanner, bool $force = false): EventRegistration
    {
        return DB::transaction(function () use ($qrToken, $scanner, $force): EventRegistration {
            $registration = EventRegistration::query()
                ->with(['event.circle', 'occurrence', 'user'])
                ->where('qr_token', $qrToken)
                ->lockForUpdate()
                ->first();

            if (! $registration) {
                throw ValidationException::withMessages(['qr_token' => 'QR token not found.']);
            }
            if ($registration->status === 'cancelled') {
                throw ValidationException::withMessages(['registration' => 'Registration is cancelled.']);
            }
            if (! $registration->occurrence) {
                throw ValidationException::withMessages(['occurrence' => 'Event occurrence not found.']);
            }
            if (! $registration->event || ! $registration->event->qr_checkin_enabled) {
                throw ValidationException::withMessages(['event' => 'QR check-in is not enabled for this event.']);
            }
            if ($registration->checkin_status === 'checked_in' && ! ($force && $this->events->isAdmin($scanner))) {
                throw ValidationException::withMessages(['registration' => 'Attendee is already checked in.']);
            }

            $registration->forceFill([
                'status' => 'attended',
                'checkin_status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by_user_id' => $scanner->id,
            ])->save();

            return $registration->fresh(['event.circle', 'occurrence', 'user', 'checkedInBy']);
        });
    }
}
