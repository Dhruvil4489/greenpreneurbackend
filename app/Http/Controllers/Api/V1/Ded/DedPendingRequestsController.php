<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DedPendingRequestsController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function summary(Request $request)
    {
        return $this->ded->success($this->ded->pendingRequestCounts($this->ded->admin($request)), 'DED pending request summary loaded.');
    }

    public function index(Request $request, string $type)
    {
        $this->validateList($request, $type);
        $admin = $this->ded->admin($request);
        $this->ded->assertCircleInScope($admin, $request->query('circle_id'));
        $query = $this->queryFor($type, $request);
        $this->ded->filterPendingQuery($query, $request, $type);
        $paginator = $query->latest('created_at')->paginate($this->ded->perPage($request));

        return $this->ded->success($paginator->items(), 'DED pending requests loaded.', $this->ded->paginationMeta($paginator));
    }

    public function show(Request $request, string $type, string $id)
    {
        $record = $this->queryFor($type, $request)->findOrFail($id);

        return $this->ded->success($record, 'DED pending request loaded.');
    }

    public function approve(Request $request, string $type, string $id)
    {
        $request->validate(['admin_note' => ['nullable', 'string', 'max:2000'], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $record = $this->ded->approveOrReject($type, $id, $type === 'circle_joining_requests' ? 'ded-approve' : 'approve', $request);

        return $this->ded->success($record, 'DED approval completed successfully.');
    }

    public function reject(Request $request, string $type, string $id)
    {
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = $this->ded->approveOrReject($type, $id, 'reject', $request);

        return $this->ded->success($record, 'DED rejection completed successfully.');
    }

    private function queryFor(string $type, Request $request)
    {
        $admin = $this->ded->admin($request);
        $circleId = $request->query('circle_id');

        return match ($type) {
            'visitor_registrations' => $this->ded->visitorRegistrationsQuery($admin, $circleId),
            'event_joining_requests' => $this->ded->eventJoiningRequestsQuery($admin, $circleId),
            'coin_claims' => $this->ded->coinClaimsQuery($admin, $circleId),
            'circle_joining_requests' => $this->ded->circleJoinRequestsQuery($admin, $circleId),
            'pending_impacts' => $this->ded->impactsQuery($admin, $circleId)->where('status', 'pending'),
            default => abort(404, 'Unsupported pending request type.'),
        };
    }

    private function validateList(Request $request, string $type): void
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'circle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
        if ($type === 'event_joining_requests') {
            $rules['event_id'] = ['nullable', 'uuid'];
        }
        $request->validate($rules);
    }
}
