<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Event\ZohoEventFormWebhookRequest;
use App\Http\Resources\Event\EventRegistrationResource;
use App\Services\Events\ZohoEventFormWebhookService;
use Illuminate\Http\JsonResponse;

class ZohoEventFormWebhookController extends BaseApiController
{
    public function __construct(private readonly ZohoEventFormWebhookService $webhook) {}

    public function __invoke(ZohoEventFormWebhookRequest $request): JsonResponse
    {
        $registration = $this->webhook->handle($request->all());

        return $this->success([
            'processed' => (bool) $registration,
            'registration' => $registration ? new EventRegistrationResource($registration) : null,
        ], $registration ? 'Zoho event registration processed.' : 'Zoho event webhook logged.');
    }
}
