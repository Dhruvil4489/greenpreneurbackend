<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use App\Services\Events\EventScannerQrScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanAppEventController extends BaseApiController
{
    public function __construct(private readonly EventScannerQrScanService $scannerQrScans) {}

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

    public function scan(Request $request, string $eventId)
    {
        $scanner = $this->activeScanner($request, false);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        $data = $request->validate([
            'qr_token' => ['required', 'string', 'max:512'],
            'device_info' => ['nullable', 'array'],
        ]);

        return $this->scannerScanResponse($this->scannerQrScans->scan(
            $scanner,
            trim($data['qr_token']),
            $data['device_info'] ?? null,
            $eventId
        ));
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

    private function activeScanner(Request $request, bool $requireActive = true): ScanAppUser|JsonResponse
    {
        $scanner = auth()->user();
        if (! $scanner instanceof ScanAppUser) {
            return response()->json([
                'success' => false,
                'message' => 'This API is only available for scanner app users.',
            ], 403);
        }

        if ($requireActive && ! $scanner->is_active) {
            return $this->error('Scanner account is inactive.', 403);
        }

        return $scanner;
    }

    private function scannerScanResponse(array $result)
    {
        if ($result['success']) {
            return $this->success($result['data'], $result['message'], $result['status']);
        }

        if ($result['errors'] !== null) {
            return $this->error($result['message'], $result['status'], $result['errors']);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], $result['status']);
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

}
