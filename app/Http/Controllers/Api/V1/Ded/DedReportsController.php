<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;

class DedReportsController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function referrals(Request $request)
    {
        return $this->activityReport($request, 'referrals', 'DED referral report loaded.');
    }

    public function activities(Request $request)
    {
        $data = [
            'summary' => $this->ded->activitySummary($this->ded->admin($request), $request),
            'dashboard' => $this->ded->dashboard($this->ded->admin($request), $request),
        ];

        return $this->ded->success($data, 'DED activity report loaded.');
    }

    public function coins(Request $request)
    {
        $paginator = $this->ded->coinLedgerQuery($this->ded->admin($request), $request)
            ->latest('created_at')
            ->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED coins report loaded.', $this->ded->paginationMeta($paginator));
    }

    public function pendingRequests(Request $request)
    {
        return $this->ded->success($this->ded->pendingRequestCounts($this->ded->admin($request)), 'DED pending requests report loaded.');
    }


    public function referralReport(Request $request)
    {
        $data = $this->ded->referralReport($request, $this->ded->admin($request));

        return $this->ded->success($data['items'], 'DED referral report loaded.', $data['meta']);
    }

    public function lifeImpact(Request $request)
    {
        $data = $this->ded->lifeImpact($request, $this->ded->admin($request));

        return $this->ded->success($data['items'], 'DED life impact loaded.', $data['meta']);
    }

    private function activityReport(Request $request, string $type, string $message)
    {
        $query = $this->ded->activityQuery($type, $this->ded->admin($request), $request);
        $paginator = $query->latest('created_at')->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), $message, $this->ded->paginationMeta($paginator));
    }
}
