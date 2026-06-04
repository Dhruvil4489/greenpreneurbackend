<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Event\EventRegistrationResource;
use App\Models\Event;
use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use App\Services\Events\EventCheckinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScanAppEventController extends BaseApiController
{
    public function __construct(private readonly EventCheckinService $checkins) {}

    public function index(Request $request)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        $events = Event::query()
            ->when($scanner->event_id, fn ($q) => $q->where('id', $scanner->event_id))
            ->when(! $scanner->event_id, fn ($q) => $q->where('start_at', '>=', now()->subDay())->orderBy('start_at'))
            ->get();

        return $this->success([
            'items' => $events->map(fn (Event $event) => $this->eventPayload($event))->values(),
        ], 'Events fetched successfully.');
    }

    public function scan(Request $request, Event $event)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        $data = $request->validate([
            'qr_token' => ['required', 'string', 'max:512'],
            'device_info' => ['nullable', 'array'],
        ]);

        $qrToken = trim($data['qr_token']);
        $deviceInfo = $data['device_info'] ?? null;

        if ($scanner->event_id && (string) $scanner->event_id !== (string) $event->id) {
            $message = 'Scanner is not assigned to this event.';
            $this->writeScanLog($event->id, null, $scanner->id, $qrToken, 'wrong_event', $message, $deviceInfo, [
                'route_event_id' => $event->id,
                'assigned_event_id' => $scanner->event_id,
            ]);

            return $this->error($message, 403);
        }

        try {
            $registration = $this->checkins->scanForScannerApp($qrToken, $scanner, $event->id);

            $this->writeScanLog($event->id, $registration->user_id, $scanner->id, $qrToken, 'success', 'Attendance marked successfully.', $deviceInfo, [
                'registration_id' => $registration->id,
                'occurrence_id' => $registration->occurrence_id,
                'checkin_status' => $registration->checkin_status,
            ]);

            return $this->success(new EventRegistrationResource($registration), 'Attendance marked successfully.');
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception);
            $registration = $this->checkins->registrationForToken($qrToken);
            $scanStatus = $this->scanStatusForValidationMessage($message);
            $logEventId = $registration?->event_id ?: $event->id;

            $this->writeScanLog($logEventId, $registration?->user_id, $scanner->id, $qrToken, $scanStatus, $message, $deviceInfo, [
                'route_event_id' => $event->id,
                'registration_id' => $registration?->id,
                'registration_event_id' => $registration?->event_id,
                'checkin_status' => $registration?->checkin_status,
            ]);

            return $this->error($message, 422, ['scan_status' => $scanStatus]);
        } catch (Throwable $exception) {
            Log::error('scan_app_qr_scan_failed', ['error' => $exception->getMessage(), 'event_id' => $event->id, 'scanner_id' => $scanner->id]);

            $this->writeScanLog($event->id, null, $scanner->id, $qrToken, 'failed', 'Unable to scan QR. Please try again.', $deviceInfo, [
                'route_event_id' => $event->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->error('Unable to scan QR. Please try again.', 500, ['scan_status' => 'failed']);
        }
    }

    public function attendanceHistory(Request $request, Event $event)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        if ($scanner->event_id && $scanner->event_id !== $event->id) {
            return $this->error('Scanner is not assigned to this event.', 403);
        }

        $registrations = EventRegistration::query()
            ->with(['user'])
            ->where('event_id', $event->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('registered_at')
            ->get();

        $scanLogs = EventQrScanLog::query()
            ->with('scanner')
            ->where('event_id', $event->id)
            ->whereIn('scan_status', ['success', 'already_checked_in'])
            ->latest('scanned_at')
            ->get()
            ->keyBy('qr_token');

        $checkedIn = $registrations->where('checkin_status', 'checked_in')->values();
        $pending = $registrations->reject(fn (EventRegistration $registration) => $registration->checkin_status === 'checked_in')->values();

        return $this->success([
            'event' => $this->eventPayload($event),
            'scanner' => $this->scannerPayload($scanner),
            'summary' => [
                'total_registered' => $registrations->count(),
                'checked_in' => $checkedIn->count(),
                'pending' => $pending->count(),
            ],
            'checked_in_users' => $checkedIn->map(fn (EventRegistration $registration) => $this->historyAttendeePayload($registration, $scanLogs->get($registration->qr_token)))->values(),
            'pending_users' => $pending->map(fn (EventRegistration $registration) => $this->historyAttendeePayload($registration))->values(),
        ], 'Attendance history fetched successfully.');
    }

    private function activeScanner(Request $request): ScanAppUser|\Illuminate\Http\JsonResponse
    {
        $scanner = $request->user();
        if (! $scanner instanceof ScanAppUser) {
            return $this->error('Scanner authentication required.', 401);
        }

        if (! $scanner->is_active) {
            return $this->error('Scanner account is inactive.', 403);
        }

        return $scanner;
    }

    private function eventPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->title,
            'title' => $event->title,
            'date' => optional($event->start_at)->toISOString(),
            'venue' => $event->location_text,
            'hotel' => $event->location_text,
            'location' => $event->location_text,
        ];
    }

    private function scannerPayload(ScanAppUser $scanner): array
    {
        return [
            'id' => $scanner->id,
            'name' => $scanner->name,
            'hotel_name' => $scanner->hotel_name,
        ];
    }

    private function attendeePayload(EventRegistration $registration): array
    {
        $user = $registration->user;

        return [
            'id' => $user?->id,
            'name' => $user?->display_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: $registration->visitor_name,
            'email' => $user?->email ?? $registration->visitor_email,
            'phone' => $user?->phone ?? $registration->visitor_phone,
        ];
    }

    private function historyAttendeePayload(EventRegistration $registration, ?EventQrScanLog $scanLog = null): array
    {
        $payload = $this->attendeePayload($registration);
        $payload['company_name'] = $registration->user?->company_name ?? $registration->visitor_company;
        $payload['checked_in_at'] = optional($registration->checked_in_at)->toISOString();
        $payload['scanned_by'] = $scanLog?->scanner?->name;
        $payload['hotel_name'] = $scanLog?->scanner?->hotel_name;

        return $payload;
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: 'Unable to scan QR.';
    }

    private function scanStatusForValidationMessage(string $message): string
    {
        return match ($message) {
            'QR token not found.', 'QR code has not been generated for this registration.' => 'invalid_qr',
            'QR code does not belong to this event.' => 'wrong_event',
            'Attendance already marked.' => 'already_checked_in',
            default => 'failed',
        };
    }

    private function writeScanLog(string $eventId, ?string $userId, string $scannerId, string $qrToken, string $status, string $message, ?array $deviceInfo, array $meta): void
    {
        try {
            EventQrScanLog::query()->create([
                'event_id' => $eventId,
                'user_id' => $userId,
                'scanner_id' => $scannerId,
                'qr_token' => $qrToken,
                'scan_status' => $status,
                'scan_message' => $message,
                'scanned_at' => now(),
                'device_info' => $deviceInfo,
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            Log::error('event_qr_scan_log_write_failed', ['error' => $exception->getMessage(), 'event_id' => $eventId, 'scanner_id' => $scannerId, 'scan_status' => $status]);
        }
    }
}
