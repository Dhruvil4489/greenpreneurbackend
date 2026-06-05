<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\Events\EventQrService;
use App\Services\Events\EventRegistrationQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MyEventQrController extends BaseApiController
{
    public function __construct(
        private readonly EventQrService $qr,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'This API is only available for Unity users.',
            ], 403);
        }

        try {
            $registrations = EventRegistration::query()
                ->with(['event', 'occurrence'])
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->orderByDesc('registered_at')
                ->orderByDesc('created_at')
                ->get();

            return $this->success([
                'items' => $registrations->map(fn (EventRegistration $registration) => $this->itemPayload($registration))->values(),
            ], 'My events with QR codes fetched successfully.');
        } catch (Throwable $exception) {
            Log::error('my_events_with_qr_fetch_failed', [
                'user_id' => (string) $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch my events with QR codes. Please try again.',
                'data' => ['items' => []],
            ]);
        }
    }

    private function itemPayload(EventRegistration $registration): array
    {
        $registration = $this->ensureQr($registration);
        $event = $registration->event;
        $occurrence = $registration->occurrence;
        $startsAt = $occurrence?->start_at ?? $event?->start_at;
        $endsAt = $occurrence?->end_at ?? $event?->end_at;
        $metadata = $this->metadata($event?->metadata);

        return [
            'event_id' => $registration->event_id,
            'occurrence_id' => $registration->occurrence_id,
            'registration_id' => $registration->id,
            'event_name' => $event?->title,
            'event_date' => optional($occurrence?->occurrence_date ?? $startsAt)->format('Y-m-d'),
            'start_time' => optional($startsAt)->format('H:i'),
            'end_time' => optional($endsAt)->format('H:i'),
            'location' => $metadata['city'] ?? $event?->location_text,
            'venue' => $metadata['venue_name'] ?? $event?->location_text,
            'registration_status' => $registration->status,
            'payment_status' => $registration->payment_status ?? 'not_required',
            'checkin_status' => $registration->checkin_status,
            'checked_in_at' => optional($registration->checked_in_at)->toISOString(),
            'qr_token' => $registration->qr_token,
            'qr_code_url' => $this->qrCodeUrl($registration),
        ];
    }

    private function ensureQr(EventRegistration $registration): EventRegistration
    {
        return $this->registrationQr->ensureQrGenerated($registration)->fresh(['event', 'occurrence']) ?? $registration;
    }

    private function qrCodeUrl(EventRegistration $registration): ?string
    {
        $ensuredUrl = $this->registrationQr->qrCodeUrl($registration);
        if (! empty($ensuredUrl)) {
            return $ensuredUrl;
        }

        if (! empty($registration->qr_code_url)) {
            $convertedUrl = $this->convertStoredQrUrl($registration);

            return $convertedUrl ?? $registration->qr_code_url;
        }

        return null;
    }

    private function convertStoredQrUrl(EventRegistration $registration): ?string
    {
        $path = parse_url((string) $registration->qr_code_url, PHP_URL_PATH) ?: (string) $registration->qr_code_url;
        $path = ltrim($path, '/');

        if (str_contains($path, 'event-qrcodes/')) {
            $relativePath = substr($path, strpos($path, 'event-qrcodes/'));
            $segments = explode('/', $relativePath);

            if (count($segments) >= 3) {
                return $this->qr->url('event-qrcodes/'.$segments[1].'/'.basename($segments[2]));
            }
        }

        if (str_contains($path, '/storage/') || str_starts_with($path, 'storage/')) {
            return $this->qr->url('event-qrcodes/'.$registration->event_id.'/'.basename($path));
        }

        return null;
    }

    private function metadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? $metadata : [];
    }
}
