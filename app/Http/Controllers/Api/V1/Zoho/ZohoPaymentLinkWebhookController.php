<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Http\Request;

class ZohoPaymentLinkWebhookController extends Controller
{
    public function __construct(private readonly ZohoPaymentWebhookService $webhooks) {}

    public function handle(Request $request)
    {
        $result = $this->webhooks->handle($request);

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Webhook received.',
            'data' => [
                'registration_found' => $result['registration_found'] ?? null,
                'registration_id' => $result['registration_id'] ?? null,
                'webhook_event_id' => $result['webhook_event_id'] ?? null,
            ],
        ]);
    }
}
