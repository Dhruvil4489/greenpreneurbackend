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

        $result = $this->webhooks->handle($request);
        return response()->json(['success' => true, 'message' => $result['message'] ?? 'Webhook received.']);
    }
}
