<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\PeerMonthlyImpactScriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PeerMonthlyImpactScriptController extends BaseApiController
{
    public function __construct(private readonly PeerMonthlyImpactScriptService $service)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->success(
                $this->service->buildForUser($request->user()),
                'Peer monthly impact script fetched successfully.'
            );
        } catch (Throwable $e) {
            Log::error('peer_monthly_impact_script.fetch_failed', [
                'user_id' => optional($request->user())->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to fetch peer monthly impact script.', 500);
        }
    }
}
