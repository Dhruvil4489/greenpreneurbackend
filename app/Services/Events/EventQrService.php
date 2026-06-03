<?php

namespace App\Services\Events;

use App\Exceptions\QrGenerationException;
use App\Models\EventRegistration;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EventQrService
{
    public function generateToken(): string
    {
        return hash_hmac('sha256', Str::random(80).'|'.Str::uuid(), config('app.key'));
    }

    public function payload(string $qrToken): string
    {
        return url('/api/v1/events/checkin/qr/'.$qrToken);
    }

    public function generateAndStore(EventRegistration $registration): array
    {
        if (! extension_loaded('gd')) {
            Log::error('event_qr_generation_gd_missing', [
                'event_registration_id' => (string) $registration->id,
                'event_id' => (string) $registration->event_id,
            ]);

            throw QrGenerationException::gdMissing();
        }

        $payload = $this->payload($registration->qr_token);

        try {
            $png = $this->makePng($payload);
        } catch (Throwable $exception) {
            Log::error('event_qr_generation_failed', [
                'event_registration_id' => (string) $registration->id,
                'event_id' => (string) $registration->event_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw QrGenerationException::gdMissing();
        }

        $relativePath = 'event-qrcodes/'.$registration->event_id.'/'.$registration->id.'.png';

        Storage::disk('public')->put($relativePath, $png);

        $url = $this->url($relativePath);
        $qrData = ['path' => $relativePath, 'url' => $url];

        $updates = [
            'qr_code_path' => $relativePath,
            'qr_code_url' => $url,
        ];

        if (Schema::hasColumn('event_registrations', 'qr_code_svg')) {
            $updates['qr_code_svg'] = null;
        }

        if (Schema::hasColumn('event_registrations', 'qr_generated_at')) {
            $updates['qr_generated_at'] = now();
        }

        $registration->update($updates);

        return $qrData;
    }

    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $path = ltrim($path, '/');
        $segments = explode('/', $path);

        if (count($segments) === 3 && $segments[0] === 'event-qrcodes') {
            return rtrim((string) config('app.url'), '/').'/api/v1/event-qrcodes/'.$segments[1].'/'.$segments[2];
        }

        return rtrim((string) config('app.url'), '/').'/api/v1/event-qrcodes/'.basename(dirname($path)).'/'.basename($path);
    }

    private function makePng(string $payload): string
    {
        $writer = new PngWriter();
        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 1024,
            margin: 40,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        return $writer->write($qrCode)->getString();
    }
}
