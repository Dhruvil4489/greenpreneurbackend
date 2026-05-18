<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Event\ZohoEventFormWebhookRequest;
use App\Services\Events\ZohoEventFormWebhookService;
use Illuminate\Http\JsonResponse;

class ZohoEventFormWebhookController extends BaseApiController
{
    public function __construct(private readonly ZohoEventFormWebhookService $webhook) {}

    public function __invoke(ZohoEventFormWebhookRequest $request): JsonResponse
    {
        $secret = (string) env('ZOHO_EVENT_FORM_WEBHOOK_SECRET', '');
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-Zoho-Event-Secret', ''))) {
            return $this->error('Invalid Zoho event webhook secret.', 403);
        }

        $registration = $this->webhook->handle($request->all());

        return $this->success([
            'registration_id' => $registration->id,
            'qr_code_url' => $registration->qr_code_url,
            'status' => $registration->status,
            'source' => $registration->source,
        ], 'Zoho event registration synced successfully.');
    }
}
