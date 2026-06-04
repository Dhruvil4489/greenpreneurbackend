<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Impacts\StoreImpactRequest;
use App\Http\Requests\Impacts\ReviewImpactRequest;
use App\Models\Impact;
use App\Models\User;
use App\Services\Impacts\ImpactActionService;
use App\Services\Impacts\ImpactService;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ImpactsController extends Controller
{
    public function __construct(
        private readonly ImpactService $impactService,
        private readonly ImpactActionService $impactActionService,
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureGlobalAdmin();

        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('q', ''));
        $headerStatus = (string) $request->query('filter_status', '');

        if ($headerStatus !== '') {
            $status = $headerStatus;
        }

        $filterDate = trim((string) $request->query('filter_date', ''));
        $filterAction = trim((string) $request->query('filter_action', ''));
        $filterImpactedPeer = trim((string) $request->query('filter_impacted_peer', ''));
        $filterSubmittedBy = trim((string) $request->query('filter_submitted_by', ''));
        $filterApprovedBy = trim((string) $request->query('filter_approved_by', ''));

        if (! in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
            $status = 'all';
        }

        $impacts = Impact::query()
            ->with([
                'user:id,display_name,first_name,last_name,email,company_name,business_type',
                'impactedPeer:id,display_name,first_name,last_name,email,company_name,business_type',
                'approvedBy:id,name',
            ])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filterDate !== '', fn ($query) => $query->whereDate('impact_date', $filterDate))
            ->when($filterAction !== '', fn ($query) => $query->where('action', $filterAction))
            ->when($filterImpactedPeer !== '', function ($query) use ($filterImpactedPeer) {
                $term = "%{$filterImpactedPeer}%";

                $query->whereHas('impactedPeer', function ($peerQuery) use ($term) {
                    $peerQuery->where('display_name', 'ILIKE', $term)
                        ->orWhere('first_name', 'ILIKE', $term)
                        ->orWhere('last_name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('company_name', 'ILIKE', $term)
                        ->orWhere('city', 'ILIKE', $term);
                });
            })
            ->when($filterSubmittedBy !== '', function ($query) use ($filterSubmittedBy) {
                $term = "%{$filterSubmittedBy}%";

                $query->whereHas('user', function ($userQuery) use ($term) {
                    $userQuery->where('display_name', 'ILIKE', $term)
                        ->orWhere('first_name', 'ILIKE', $term)
                        ->orWhere('last_name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('company_name', 'ILIKE', $term)
                        ->orWhere('city', 'ILIKE', $term);
                });
            })
            ->when($filterApprovedBy !== '', function ($query) use ($filterApprovedBy) {
                $term = "%{$filterApprovedBy}%";

                $query->whereHas('approvedBy', function ($approvedByQuery) use ($term) {
                    $approvedByQuery->where('name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term);
                });
            })
            ->when($search !== '', function ($query) use ($search) {
                $term = "%{$search}%";

                $query->where(function ($subQuery) use ($term) {
                    $subQuery->where('action', 'ILIKE', $term)
                        ->orWhere('story_to_share', 'ILIKE', $term)
                        ->orWhereHas('user', function ($userQuery) use ($term) {
                            $userQuery->where('display_name', 'ILIKE', $term)
                                ->orWhere('first_name', 'ILIKE', $term)
                                ->orWhere('last_name', 'ILIKE', $term)
                                ->orWhere('email', 'ILIKE', $term);
                        })
                        ->orWhereHas('impactedPeer', function ($userQuery) use ($term) {
                            $userQuery->where('display_name', 'ILIKE', $term)
                                ->orWhere('first_name', 'ILIKE', $term)
                                ->orWhere('last_name', 'ILIKE', $term)
                                ->orWhere('email', 'ILIKE', $term);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $impactActions = Impact::availableActions();
        $adminId = (string) Auth::guard('admin')->id();
        $peers = User::query()
            ->select(['id', 'display_name', 'first_name', 'last_name', 'email', 'company_name', 'business_type', 'city'])
            ->when($adminId !== '', fn ($query) => $query->where('id', '!=', $adminId))
            ->orderByRaw("COALESCE(NULLIF(display_name, ''), NULLIF(TRIM(CONCAT(first_name, ' ', last_name)), ''), email) ASC")
            ->get();

        return view('admin.impacts.index', [
            'impacts' => $impacts,
            'impactActions' => $impactActions,
            'impactActionItems' => $this->impactActionService->listForAdmin(),
            'peers' => $peers,
            'filters' => [
                'status' => $status,
                'q' => $search,
                'filter_date' => $filterDate,
                'filter_action' => $filterAction,
                'filter_impacted_peer' => $filterImpactedPeer,
                'filter_submitted_by' => $filterSubmittedBy,
                'filter_approved_by' => $filterApprovedBy,
            ],
        ]);
    }

    public function store(StoreImpactRequest $request): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $data = $request->validated();
        $adminId = (string) Auth::guard('admin')->id();

        if ((string) $data['impacted_peer_id'] === $adminId) {
            throw ValidationException::withMessages([
                'impacted_peer_id' => 'You cannot create an impact for yourself.',
            ]);
        }

        $submittedBy = User::query()->find($adminId);

        if (! $submittedBy) {
            throw ValidationException::withMessages([
                'impacted_peer_id' => 'Current admin user is not linked to a peer account.',
            ]);
        }

        $this->impactService->submitImpact($submittedBy, $data);

        return redirect()
            ->route('admin.impacts.index')
            ->with('success', 'Impact created successfully.');
    }

    public function storeAction(Request $request): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'impact_score' => ['required', 'integer', 'min:1'],
        ], [
            'name.required' => 'Action name is required.',
            'impact_score.required' => 'Impact score is required.',
        ]);

        try {
            $this->impactActionService->createAction((string) $validated['name'], (int) $validated['impact_score']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return redirect()->route('admin.impacts.index')
                ->withInput()
                ->with('error', 'Impact actions table is not available yet. Please run the provided SQL first.');
        }

        return redirect()->route('admin.impacts.index')->with('success', 'Impact action added successfully.');
    }


    public function updateAction(Request $request, string $id): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'impact_score' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $this->impactActionService->updateAction(
                $id,
                (string) $validated['name'],
                (int) $validated['impact_score'],
                array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()->route('admin.impacts.index')->with('success', 'Impact action updated successfully.');
    }

    public function destroyAction(string $id): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $this->impactActionService->deleteOrDeactivateAction($id);

        return redirect()->route('admin.impacts.index')->with('success', 'Impact action deleted successfully.');
    }

    public function pending(): View
    {
        $this->ensureCanAccessImpactsModule();

        $query = Impact::query()
            ->with([
                'user:id,display_name,first_name,last_name,email',
                'impactedPeer:id,display_name,first_name,last_name,email',
            ])
            ->where('status', 'pending');

        $this->applyDedImpactScope($query);

        $impacts = $query
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('admin.impacts.pending', compact('impacts'));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->ensureCanAccessImpactsModule();

        $status = (string) $request->query('status', 'all');
        $headerStatus = (string) $request->query('filter_status', '');

        if ($headerStatus !== '') {
            $status = $headerStatus;
        }

        if (! in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
            $status = 'all';
        }

        $filterDate = trim((string) $request->query('filter_date', ''));
        $filterAction = trim((string) $request->query('filter_action', ''));
        $filterImpactedPeer = trim((string) $request->query('filter_impacted_peer', ''));
        $filterSubmittedBy = trim((string) $request->query('filter_submitted_by', ''));
        $filterApprovedBy = trim((string) $request->query('filter_approved_by', ''));
        $search = trim((string) $request->query('q', ''));

        $impactsQuery = Impact::query()
            ->with([
                'user:id,display_name,first_name,last_name,email',
                'impactedPeer:id,display_name,first_name,last_name,email',
                'approvedBy:id,name',
            ]);

        $this->applyDedImpactScope($impactsQuery);

        $impacts = $impactsQuery
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filterDate !== '', fn ($query) => $query->whereDate('impact_date', $filterDate))
            ->when($filterAction !== '', fn ($query) => $query->where('action', $filterAction))
            ->when($filterImpactedPeer !== '', function ($query) use ($filterImpactedPeer) {
                $term = "%{$filterImpactedPeer}%";
                $query->whereHas('impactedPeer', function ($peerQuery) use ($term) {
                    $peerQuery->where('display_name', 'ILIKE', $term)
                        ->orWhere('first_name', 'ILIKE', $term)
                        ->orWhere('last_name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('company_name', 'ILIKE', $term)
                        ->orWhere('city', 'ILIKE', $term);
                });
            })
            ->when($filterSubmittedBy !== '', function ($query) use ($filterSubmittedBy) {
                $term = "%{$filterSubmittedBy}%";
                $query->whereHas('user', function ($userQuery) use ($term) {
                    $userQuery->where('display_name', 'ILIKE', $term)
                        ->orWhere('first_name', 'ILIKE', $term)
                        ->orWhere('last_name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term)
                        ->orWhere('company_name', 'ILIKE', $term)
                        ->orWhere('city', 'ILIKE', $term);
                });
            })
            ->when($filterApprovedBy !== '', function ($query) use ($filterApprovedBy) {
                $term = "%{$filterApprovedBy}%";
                $query->whereHas('approvedBy', function ($approvedByQuery) use ($term) {
                    $approvedByQuery->where('name', 'ILIKE', $term)
                        ->orWhere('email', 'ILIKE', $term);
                });
            })
            ->when($search !== '', function ($query) use ($search) {
                $term = "%{$search}%";
                $query->where(function ($subQuery) use ($term) {
                    $subQuery->where('action', 'ILIKE', $term)
                        ->orWhere('story_to_share', 'ILIKE', $term);
                });
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->streamDownload(function () use ($impacts): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Add Impact Details',
                'Impacted Peer',
                'Submitted By / User',
                'Life Impacted',
                'Status',
                'Review Remarks',
                'Approved By',
                'Approved At',
                'Created At',
            ]);

            foreach ($impacts as $impact) {
                $impactedPeer = $impact->impactedPeer?->display_name ?: trim(($impact->impactedPeer?->first_name ?? '') . ' ' . ($impact->impactedPeer?->last_name ?? ''));
                $submittedBy = $impact->user?->display_name ?: trim(($impact->user?->first_name ?? '') . ' ' . ($impact->user?->last_name ?? ''));

                fputcsv($handle, [
                    optional($impact->impact_date)->toDateString(),
                    $impact->action,
                    $impactedPeer,
                    $submittedBy,
                    (int) ($impact->life_impacted ?? 1),
                    (string) $impact->status,
                    (string) ($impact->review_remarks ?? ''),
                    (string) ($impact->approvedBy?->name ?? ''),
                    optional($impact->approved_at)?->format('Y-m-d H:i:s'),
                    optional($impact->created_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, 'impact-list.csv');
    }

    public function posts(): RedirectResponse
    {
        return redirect()->route('admin.impacts.index', ['status' => 'approved']);
    }

    public function show(string $id): View
    {
        $impact = Impact::query()
            ->with(['user:id,display_name,first_name,last_name,email,phone', 'impactedPeer:id,display_name,first_name,last_name,email,phone'])
            ->findOrFail($id);

        $this->ensureCanAccessImpact($impact);

        $totalLifeImpactedQuery = Impact::query()
            ->where('user_id', (string) $impact->user_id)
            ->where('status', 'approved');
        $this->applyDedImpactScope($totalLifeImpactedQuery);
        $totalLifeImpacted = (int) $totalLifeImpactedQuery
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(life_impacted, 1)'));

        return view('admin.impacts.show', [
            'impact' => $impact,
            'total_life_impacted' => $totalLifeImpacted,
        ]);
    }

    public function approve(string $id, ReviewImpactRequest $request): RedirectResponse
    {
        $this->ensureCanReviewImpact($id);

        $this->impactService->approveImpact($id, (string) Auth::guard('admin')->id(), $request->validated('review_remarks'));

        return redirect()->back()->with('success', 'Impact approved successfully.');
    }

    public function reject(string $id, ReviewImpactRequest $request): RedirectResponse
    {
        $this->ensureCanReviewImpact($id);

        $this->impactService->rejectImpact($id, (string) Auth::guard('admin')->id(), $request->validated('review_remarks'));

        return redirect()->back()->with('success', 'Impact rejected successfully.');
    }


    private function ensureCanAccessImpactsModule(): void
    {
        $admin = Auth::guard('admin')->user();

        if (! AdminAccess::isGlobalAdmin($admin) && ! AdminAccess::isDed($admin)) {
            abort(403);
        }
    }

    private function applyDedImpactScope($query): void
    {
        $admin = Auth::guard('admin')->user();

        if (! AdminAccess::isDed($admin)) {
            return;
        }

        AdminCircleScope::applyToActivityQuery($query, $admin, 'impacts.user_id', 'impacts.impacted_peer_id');
    }

    private function ensureCanAccessImpact(Impact $impact): void
    {
        $admin = Auth::guard('admin')->user();

        if (AdminAccess::isGlobalAdmin($admin)) {
            return;
        }

        if (AdminAccess::isDed($admin)
            && (AdminCircleScope::userInScope($admin, (string) $impact->user_id)
                || AdminCircleScope::userInScope($admin, (string) $impact->impacted_peer_id))) {
            return;
        }

        abort(403);
    }

    private function ensureCanReviewImpact(string $id): void
    {
        $impact = Impact::query()->findOrFail($id);
        $this->ensureCanAccessImpact($impact);

        if (AdminAccess::isDed(Auth::guard('admin')->user()) && (string) $impact->status !== 'pending') {
            abort(403);
        }
    }

    private function ensureGlobalAdmin(): void
    {
        if (! AdminAccess::isGlobalAdmin(Auth::guard('admin')->user())) {
            abort(403);
        }
    }
}
