<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $payload = $this->payload($registration->qr_token);
        $svg = $this->makeSvg($payload, $registration->qr_token);
        $relativePath = 'event-qrcodes/'.$registration->event_id.'/'.$registration->id.'.svg';

        Storage::disk('public')->put($relativePath, $svg);

        $url = $this->url($relativePath);
        $qrData = ['path' => $relativePath, 'url' => $url, 'svg' => $svg];

        $updates = [
            'qr_code_path' => $relativePath,
            'qr_code_url' => $url,
            'qr_code_svg' => $svg,
        ];

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

        return url(Storage::disk('public')->url($path));
    }

    private function makeSvg(string $payload, string $token): string
    {
        if (class_exists('SimpleSoftwareIO\\QrCode\\Facades\\QrCode')) {
            return (string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(320)->margin(2)->generate($payload);
        }

        $hash = hash('sha256', $payload);
        $cells = 29;
        $cell = 10;
        $size = $cells * $cell;
        $rects = [];

        $finder = function (int $x, int $y) use (&$rects, $cell): void {
            $rects[] = sprintf('<rect x="%d" y="%d" width="70" height="70" fill="#000"/>', $x * $cell, $y * $cell);
            $rects[] = sprintf('<rect x="%d" y="%d" width="50" height="50" fill="#fff"/>', ($x + 1) * $cell, ($y + 1) * $cell);
            $rects[] = sprintf('<rect x="%d" y="%d" width="30" height="30" fill="#000"/>', ($x + 2) * $cell, ($y + 2) * $cell);
        };

        $finder(0, 0);
        $finder(22, 0);
        $finder(0, 22);

        for ($y = 0; $y < $cells; $y++) {
            for ($x = 0; $x < $cells; $x++) {
                if (($x < 8 && $y < 8) || ($x > 20 && $y < 8) || ($x < 8 && $y > 20)) {
                    continue;
                }
                $i = ($x + ($y * $cells)) % strlen($hash);
                if (hexdec($hash[$i]) % 2 === 0) {
                    $rects[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="#000"/>', $x * $cell, $y * $cell, $cell, $cell);
                }
            }
        }

        $safePayload = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $rectMarkup = $this->implodeRects($rects);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}" role="img" aria-label="Event QR token {$safeToken}">
  <rect width="100%" height="100%" fill="#fff"/>
  <metadata>{$safePayload}</metadata>
  <g>{$rectMarkup}</g>
</svg>
SVG;
    }

    private function implodeRects(array $rects): string
    {
        return implode('', $rects);
    }
}
