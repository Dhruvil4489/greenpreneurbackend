<?php

namespace App\Services\Events;

use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            if ($registration->status === 'pending_payment' || (($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid')) {
                throw ValidationException::withMessages(['registration' => 'Payment is required before QR check-in.']);
            }
            if (empty($registration->qr_code_path) && empty($registration->qr_code_url)) {
                throw ValidationException::withMessages(['registration' => 'QR code has not been generated for this registration.']);
            }
            if (! $registration->occurrence) {
                throw ValidationException::withMessages(['occurrence' => 'Event occurrence not found.']);
            }
            if (! $registration->event || ! $registration->event->qr_checkin_enabled) {
                throw ValidationException::withMessages(['event' => 'QR check-in is not enabled for this event.']);
            }
            if ($registration->checkin_status === 'checked_in' && ! ($force && $this->events->isAdmin($scanner))) {
                throw ValidationException::withMessages(['registration' => 'Attendance already marked.']);
            }

            $updates = [
                'status' => 'attended',
                'checkin_status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by_user_id' => $scanner->id,
            ];

            if (Schema::hasColumn('event_registrations', 'last_qr_scan_at')) {
                $updates['last_qr_scan_at'] = now();
            }
            if (Schema::hasColumn('event_registrations', 'attendance_source')) {
                $updates['attendance_source'] = 'qr_scan';
            }

            $registration->forceFill($updates)->save();

            $occurrence = EventOccurrence::query()
                ->where('id', $registration->occurrence_id)
                ->lockForUpdate()
                ->first();

            if ($occurrence) {
                $checkedInCount = EventRegistration::query()
                    ->where('occurrence_id', $registration->occurrence_id)
                    ->where('checkin_status', 'checked_in')
                    ->whereNull('deleted_at')
                    ->count();

                $registeredCount = EventRegistration::query()
                    ->where('occurrence_id', $registration->occurrence_id)
                    ->where('status', '!=', 'cancelled')
                    ->whereNull('deleted_at')
                    ->count();

                $occurrenceUpdates = ['registered_count' => $registeredCount];
                if (Schema::hasColumn('event_occurrences', 'checked_in_count')) {
                    $occurrenceUpdates['checked_in_count'] = $checkedInCount;
                }
                $occurrence->forceFill($occurrenceUpdates)->save();
            }

            return $registration->fresh(['event.circle', 'occurrence', 'user', 'checkedInBy']);
        });
    }
}
