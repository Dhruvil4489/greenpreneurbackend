<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Models\CircleJoinRequest;
use App\Services\Circles\CircleJoinRequestService;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CircleJoinRequestsController extends Controller
{
    public function __construct(private readonly CircleJoinRequestService $service)
    {
    }

    public function index(Request $request): View
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);

        $query = CircleJoinRequest::query()->with(['user', 'circle', 'cdApprovedBy', 'cdRejectedBy', 'idApprovedBy', 'idRejectedBy']);
        $query->visibleToAdminUser($admin);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->whereHas('user', fn ($q) => $q->where('display_name', 'ILIKE', $like)
                ->orWhere('email', 'ILIKE', $like)
                ->orWhere('phone', 'ILIKE', $like)
                ->orWhere('company_name', 'ILIKE', $like)
                ->orWhere('city', 'ILIKE', $like));
        }

        $pendingStatuses = [
            CircleJoinRequest::STATUS_PENDING_CD_APPROVAL,
            CircleJoinRequest::STATUS_PENDING_ID_APPROVAL,
            CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE,
        ];

        $query->whereIn('status', $pendingStatuses)
            ->when($request->query('circle_id'), fn ($q, $v) => $q->where('circle_id', $v))
            ->when($request->query('status'), fn ($q, $v) => in_array($v, $pendingStatuses, true) ? $q->where('status', $v) : $q->whereRaw('1=0'))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('requested_at', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('requested_at', '<=', $v));

        if (Schema::hasColumn('circle_join_requests', 'fee_paid_at')) {
            $query->whereNull('fee_paid_at');
        }

        $requests = $query->latest('created_at')->paginate(25)->appends($request->query());

        $requests->getCollection()->transform(function (CircleJoinRequest $joinRequest) use ($admin, $actor) {
            $joinRequest->setAttribute('can_approve_cd', $this->canApproveCd($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_reject_cd', $this->canApproveCd($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_approve_id', $this->canApproveId($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_reject_id', $this->canApproveId($admin, $actor, $joinRequest));

            return $joinRequest;
        });

        return view('admin.circle_join_requests.index', [
            'requests' => $requests,
            'circles' => Circle::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'circle_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(string $id): View
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);

        $record = CircleJoinRequest::query()->with(['user', 'circle', 'cdApprovedBy', 'cdRejectedBy', 'idApprovedBy', 'idRejectedBy'])->findOrFail($id);
        abort_unless($this->canAccessRecord($admin, $actor, $record), 403);

        $selectedCategoryIds = $this->resolveSelectedCategoryIds($record);

        return view('admin.circle_join_requests.show', [
            'record' => $record,
            'canApproveCd' => $this->canApproveCd($admin, $actor, $record),
            'canApproveId' => $this->canApproveId($admin, $actor, $record),
            'categoryPath' => [
                'level1' => $selectedCategoryIds['level1_category_id'] ? CircleCategory::query()->find($selectedCategoryIds['level1_category_id']) : null,
                'level2' => $selectedCategoryIds['level2_category_id'] ? CircleCategoryLevel2::query()->find($selectedCategoryIds['level2_category_id']) : null,
                'level3' => $selectedCategoryIds['level3_category_id'] ? CircleCategoryLevel3::query()->find($selectedCategoryIds['level3_category_id']) : null,
                'level4' => $selectedCategoryIds['level4_category_id'] ? CircleCategoryLevel4::query()->find($selectedCategoryIds['level4_category_id']) : null,
            ],
        ]);
    }

    public function approveCd(string $id): RedirectResponse
    {
        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor): void {
            abort_unless($this->canApproveCd($admin, $actor, $record), 403);
            $this->approveRequest($record, $actor);
        });
    }

    public function rejectCd(Request $request, string $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor) use ($request): void {
            abort_unless($this->canApproveCd($admin, $actor, $record), 403);
            $this->rejectRequest($record, $actor, (string) $request->input('reason'));
        });
    }

    public function approveId(string $id): RedirectResponse
    {
        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor): void {
            abort_unless($this->canApproveId($admin, $actor, $record), 403);
            $this->approveRequest($record, $actor);
        });
    }

    public function rejectId(Request $request, string $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor) use ($request): void {
            abort_unless($this->canApproveId($admin, $actor, $record), 403);
            $this->rejectRequest($record, $actor, (string) $request->input('reason'));
        });
    }

    private function runAction(string $id, callable $callback): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);
        abort_unless($admin !== null && $actor !== null, 403);

        try {
            $record = CircleJoinRequest::query()->with('circle')->findOrFail($id);
            abort_unless($this->canAccessRecord($admin, $actor, $record), 403);

            $oldStatus = (string) $record->status;
            $callback($record, $admin, $actor);
            $freshRecord = CircleJoinRequest::query()->findOrFail($id);

            Log::info('circle_join_request.admin_action_completed', [
                'request_id' => $freshRecord->id,
                'old_status' => $oldStatus,
                'new_status' => (string) $freshRecord->status,
                'actor_user_id' => $actor->id ?? null,
                'admin_user_id' => $admin->id ?? null,
            ]);

            return back()->with('success', 'Action completed successfully.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }
    }


    private function approveRequest(CircleJoinRequest $record, $actor): void
    {
        DB::transaction(function () use ($record, $actor): void {
            $request = CircleJoinRequest::query()->lockForUpdate()->findOrFail($record->id);
            $oldStatus = (string) $request->status;

            if ($oldStatus === CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
                $request->status = CircleJoinRequest::STATUS_PENDING_ID_APPROVAL;
                $request->cd_approved_by = $actor->id;
                $request->cd_approved_at = now();
                $request->cd_rejected_by = null;
                $request->cd_rejected_at = null;
                $request->cd_rejection_reason = null;
            } elseif ($oldStatus === CircleJoinRequest::STATUS_PENDING_ID_APPROVAL) {
                $request->status = CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE;
                $request->id_approved_by = $actor->id;
                $request->id_approved_at = now();
                $request->id_rejected_by = null;
                $request->id_rejected_at = null;
                $request->id_rejection_reason = null;
            } else {
                throw ValidationException::withMessages([
                    'status' => ["Invalid status transition from {$oldStatus}."],
                ]);
            }

            $request->save();
            $request->refresh();

            Log::info('Circle join request status changed', [
                'request_id' => $request->id,
                'from' => $oldStatus,
                'to' => $request->status,
            ]);
        });
    }

    private function rejectRequest(CircleJoinRequest $record, $actor, string $reason): void
    {
        if ((string) $record->status === CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
            $this->service->rejectByCd($record, $actor, $reason);

            return;
        }

        if ((string) $record->status === CircleJoinRequest::STATUS_PENDING_ID_APPROVAL) {
            $this->service->rejectById($record, $actor, $reason);
        }
    }

    private function resolveSelectedCategoryIds(CircleJoinRequest $record): array
    {
        $notes = $record->notes;
        $notesSelection = is_array($notes) ? ($notes['category_selection'] ?? []) : [];

        $resolve = static function (string $key) use ($record, $notesSelection): ?int {
            $value = $record->getAttribute($key);
            if ($value !== null) {
                return (int) $value;
            }

            if (is_array($notesSelection) && array_key_exists($key, $notesSelection) && $notesSelection[$key] !== null) {
                return (int) $notesSelection[$key];
            }

            return null;
        };

        return [
            'level1_category_id' => $resolve('level1_category_id'),
            'level2_category_id' => $resolve('level2_category_id'),
            'level3_category_id' => $resolve('level3_category_id'),
            'level4_category_id' => $resolve('level4_category_id'),
        ];
    }

    private function canAccessRecord($admin, $actor, CircleJoinRequest $record): bool
    {
        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        if (AdminAccess::isDed($admin)) {
            return AdminCircleScope::userInScope($admin, (string) $record->user_id);
        }

        $allowedCircleIds = AdminAccess::allowedCircleIds($admin);

        if (! in_array($record->circle_id, $allowedCircleIds, true)) {
            return false;
        }

        if (! $record->relationLoaded('circle')) {
            $record->load('circle');
        }

        return (string) $record->circle?->director_user_id === (string) $actor->id
            || (string) $record->circle?->industry_director_user_id === (string) $actor->id;
    }

    private function canApproveCd($admin, $actor, CircleJoinRequest $record): bool
    {
        if (! $this->canAccessRecord($admin, $actor, $record)) {
            return false;
        }

        if ($record->status !== CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
            return false;
        }

        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        return (string) $record->circle?->director_user_id === (string) $actor->id;
    }

    private function canApproveId($admin, $actor, CircleJoinRequest $record): bool
    {
        if (! $this->canAccessRecord($admin, $actor, $record)) {
            return false;
        }

        if ($record->status !== CircleJoinRequest::STATUS_PENDING_ID_APPROVAL) {
            return false;
        }

        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        return (string) $record->circle?->industry_director_user_id === (string) $actor->id;
    }
}
