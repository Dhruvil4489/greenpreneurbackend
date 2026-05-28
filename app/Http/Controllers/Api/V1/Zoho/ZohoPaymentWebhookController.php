<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZohoPaymentWebhookController extends Controller
{
    public function __construct(private readonly ZohoPaymentWebhookService $webhooks) {}

    public function handle(Request $request)
    {
        if (! $this->webhooks->verify($request)) {
            Log::warning('zoho_payment_webhook_signature_failed', [
                'event_type' => $request->input('event') ?? $request->input('event_type') ?? $request->input('type'),
                'payment_link_id' => $request->input('payment_link_id') ?? data_get($request->all(), 'payment_link.payment_link_id'),
                'payment_id' => $request->input('payment_id') ?? data_get($request->all(), 'payment.payment_id'),
                'status' => $request->input('status'),
            ]);
            return response()->json(['success' => false, 'message' => 'Unauthorized webhook request.'], 401);
        }

        try {
            $result = $this->webhooks->handle($request);
        } catch (\Throwable $e) {
            Log::error('zoho_payment_webhook_unhandled_exception', [
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $result = ['message' => 'Webhook received but processing failed. It can be retried.'];
        }

        $response = ['success' => true, 'message' => $result['message'] ?? 'Webhook received.'];
        if (app()->environment('local') && filter_var($request->header('X-Debug-Webhook'), FILTER_VALIDATE_BOOL)) {
            $response['debug'] = [
                'normalized' => $result['normalized'] ?? $this->webhooks->normalizeZohoPaymentWebhookPayload($request->all()),
                'webhook_event_id' => $result['webhook_event_id'] ?? null,
                'registration_found' => $result['registration_found'] ?? null,
                'registration_id' => $result['registration_id'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }

        return response()->json($response);
    }
}
