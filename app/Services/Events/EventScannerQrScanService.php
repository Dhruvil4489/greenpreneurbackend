<?php

namespace App\Services\Events;

use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventScannerQrScanService
{
    public function __construct(private readonly EventCheckinService $checkins) {}

    public function scan(ScanAppUser $scanner, string $qrToken, ?array $deviceInfo = null, ?string $expectedEventId = null): array
    {
        if (! $scanner->is_active) {
            $this->writeScanLog($expectedEventId ?? $scanner->event_id, null, $scanner->id, $qrToken, 'inactive_scanner', 'Scanner account is inactive.', $deviceInfo, [
                'scanner_event_id' => $scanner->event_id,
                'expected_event_id' => $expectedEventId,
            ]);

            return $this->result(false, 'Scanner account is inactive.', 403);
        }

        if ($expectedEventId !== null && (string) ($scanner->event_id ?? '') !== (string) $expectedEventId) {
            $message = 'Scanner is not assigned to this event.';
            $this->writeScanLog($expectedEventId, null, $scanner->id, $qrToken, 'wrong_event', $message, $deviceInfo, [
                'scanner_event_id' => $scanner->event_id,
                'expected_event_id' => $expectedEventId,
            ]);

            return $this->result(false, $message, 403);
        }

        $registrationForToken = $this->checkins->registrationForToken($qrToken);
        if (! $registrationForToken) {
            $this->writeScanLog($expectedEventId ?? $scanner->event_id, null, $scanner->id, $qrToken, 'invalid_qr', 'QR token not found.', $deviceInfo, [
                'scanner_event_id' => $scanner->event_id,
                'expected_event_id' => $expectedEventId,
            ]);

            return $this->result(false, 'QR token not found.', 422, null, ['scan_status' => 'invalid_qr']);
        }

        if ($expectedEventId !== null && (string) $registrationForToken->event_id !== (string) $expectedEventId) {
            $message = 'QR code does not belong to this event.';
            $this->writeScanLog($registrationForToken->event_id, $registrationForToken->user_id, $scanner->id, $qrToken, 'wrong_event', $message, $deviceInfo, $this->scanMeta($registrationForToken, $scanner, $expectedEventId));

            return $this->result(false, $message, 422, null, ['scan_status' => 'wrong_event']);
        }

        if ((string) ($scanner->event_id ?? '') !== (string) $registrationForToken->event_id) {
            $message = 'You are not allowed to scan QR for this event.';
            $this->writeScanLog($registrationForToken->event_id, $registrationForToken->user_id, $scanner->id, $qrToken, 'wrong_event', $message, $deviceInfo, $this->scanMeta($registrationForToken, $scanner, $expectedEventId));

            return $this->result(false, $message, 403);
        }

        try {
            $registration = $this->checkins->scanForScannerApp($qrToken, $scanner, $registrationForToken->event_id);

            $this->writeScanLog($registration->event_id, $registration->user_id, $scanner->id, $qrToken, 'success', 'Attendance marked successfully.', $deviceInfo, $this->scanMeta($registration, $scanner, $expectedEventId));

            return $this->result(true, 'Attendance marked successfully.', 200, $this->payload($registration, $scanner));
        } catch (ValidationException $exception) {
            $message = $this->validationMessage($exception);
            $scanStatus = $this->scanStatusForValidationMessage($message);

            $this->writeScanLog($registrationForToken->event_id, $registrationForToken->user_id, $scanner->id, $qrToken, $scanStatus, $message, $deviceInfo, $this->scanMeta($registrationForToken, $scanner, $expectedEventId));

            return $this->result(false, $message, 422, null, ['scan_status' => $scanStatus]);
        } catch (Throwable $exception) {
            Log::error('scanner_qr_scan_failed', [
                'error' => $exception->getMessage(),
                'event_id' => $registrationForToken->event_id,
                'scanner_id' => $scanner->id,
            ]);

            $this->writeScanLog($registrationForToken->event_id, $registrationForToken->user_id, $scanner->id, $qrToken, 'failed', 'Unable to scan QR. Please try again.', $deviceInfo, $this->scanMeta($registrationForToken, $scanner, $expectedEventId) + [
                'exception' => $exception->getMessage(),
            ]);

            return $this->result(false, 'Unable to scan QR. Please try again.', 500, null, ['scan_status' => 'failed']);
        }
    }

    private function result(bool $success, string $message, int $status, ?array $data = null, ?array $errors = null): array
    {
        return compact('success', 'message', 'status', 'data', 'errors');
    }

    private function payload(EventRegistration $registration, ScanAppUser $scanner): array
    {
        return [
            'event_id' => $registration->event_id,
            'checked_in_user' => $this->attendeePayload($registration),
            'scanner' => $this->scannerPayload($scanner),
            'checked_in_at' => optional($registration->checked_in_at)->toISOString(),
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

    private function scannerPayload(ScanAppUser $scanner): array
    {
        return [
            'id' => $scanner->id,
            'name' => $scanner->name,
            'hotel_name' => $scanner->hotel_name,
        ];
    }

    private function scanMeta(EventRegistration $registration, ScanAppUser $scanner, ?string $expectedEventId): array
    {
        return [
            'registration_id' => $registration->id,
            'occurrence_id' => $registration->occurrence_id,
            'registration_event_id' => $registration->event_id,
            'scanner_event_id' => $scanner->event_id,
            'expected_event_id' => $expectedEventId,
            'checkin_status' => $registration->checkin_status,
            'attendee_user_id' => $registration->user_id,
        ];
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?: 'Unable to scan QR.';
    }

    private function scanStatusForValidationMessage(string $message): string
    {
        return match ($message) {
            'QR token not found.', 'QR code has not been generated for this registration.' => 'invalid_qr',
            'QR code does not belong to this event.', 'You are not allowed to scan QR for this event.' => 'wrong_event',
            'Attendance already marked.' => 'already_checked_in',
            default => 'failed',
        };
    }

    private function writeScanLog(?string $eventId, ?string $userId, string $scannerId, string $qrToken, string $status, string $message, ?array $deviceInfo, array $meta): void
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
            Log::error('event_qr_scan_log_write_failed', [
                'error' => $exception->getMessage(),
                'event_id' => $eventId,
                'scanner_id' => $scannerId,
                'scan_status' => $status,
            ]);
        }
    }
}
