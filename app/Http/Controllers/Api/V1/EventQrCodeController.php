<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventQrCodeController extends Controller
{
    public function show(string $eventId, string $filename): BinaryFileResponse|JsonResponse
    {
        $path = storage_path('app/public/event-qrcodes/'.$eventId.'/'.$filename);

        if (! is_file($path)) {
            return response()->json([
                'success' => false,
                'message' => 'QR code image not found.',
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
        ]);
    }
}
