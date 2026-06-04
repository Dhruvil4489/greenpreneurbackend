<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;

class DedActivitiesController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function summary(Request $request)
    {
        $request->validate($this->listRules() + ['search' => ['nullable', 'string', 'max:255']]);
        $summary = $this->ded->activitySummary($this->ded->admin($request), $request);

        return $this->ded->success($summary, 'DED activity summary loaded.');
    }

    public function index(Request $request, string $type)
    {
        $request->validate($this->listRules() + [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
            'referral_type' => ['nullable', 'string', 'max:100'],
            'has_media' => ['nullable', 'boolean'],
        ]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $query = $this->ded->activityQuery($type, $admin, $request);
        $paginator = $query->latest($type === 'p2p-meetings' ? 'meeting_date' : 'created_at')->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED activities loaded.', $this->ded->paginationMeta($paginator));
    }


    public function recommendPeer(Request $request)
    {
        $request->validate($this->listRules() + ['search' => ['nullable', 'string', 'max:255']]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $paginator = $this->ded->peerRecommendationsQuery($admin, $request)
            ->latest('created_at')
            ->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED recommend-a-peer activities loaded.', $this->ded->paginationMeta($paginator));
    }

    public function findBuildCollaborations(Request $request)
    {
        $request->validate($this->listRules() + [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $paginator = $this->ded->collaborationsQuery($admin, $request)
            ->latest('created_at')
            ->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED collaboration activities loaded.', $this->ded->paginationMeta($paginator));
    }

    public function registerVisitor(Request $request)
    {
        $request->validate($this->listRules() + [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $paginator = $this->ded->registerVisitorQuery($admin, $request)
            ->latest('created_at')
            ->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED visitor registration activities loaded.', $this->ded->paginationMeta($paginator));
    }

    public function show(Request $request, string $type, string $id)
    {
        $query = $this->ded->activityQuery($type, $this->ded->admin($request), $request);
        $record = $query->findOrFail($id);

        return $this->ded->success($record, 'DED activity loaded.');
    }

    private function listRules(): array
    {
        return [
            'circle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
