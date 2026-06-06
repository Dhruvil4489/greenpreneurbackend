<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Models\AdminAuditLog;
use App\Models\CircleJoinRequest;
use App\Services\Admin\IndustryScopeService;
use App\Services\Circles\CircleJoinRequestService;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CircleJoinRequestsController extends Controller
{
    public function __construct(
        private readonly CircleJoinRequestService $service,
        private readonly IndustryScopeService $industryScope,
    ) {
    }

    public function index(Request $request): View
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);
        $this->reconcileDedApprovalWorkflowState();

        $query = CircleJoinRequest::query()->with(['user', 'circle', 'cdApprovedBy', 'cdRejectedBy', 'idApprovedBy', 'idRejectedBy', 'dedApprovedBy']);
        $query->visibleToAdminUser($admin);
        $query = CircleJoinRequest::query()->with(['user', 'circle', 'cdApprovedBy', 'cdRejectedBy', 'idApprovedBy', 'idRejectedBy']);

        if ($this->industryScope->isIndustryDirector($admin)) {
            $industryCircleIds = $this->industryScope->circleIdsForAdmin($admin);
            $query->when($industryCircleIds !== [], fn ($q) => $q->whereIn('circle_id', $industryCircleIds), fn ($q) => $q->whereRaw('1 = 0'));
        } else {
            $query->visibleToAdminUser($admin);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->whereHas('user', fn ($q) => $q->where('display_name', 'ILIKE', $like)
                ->orWhere('email', 'ILIKE', $like)
                ->orWhere('phone', 'ILIKE', $like)
                ->orWhere('company_name', 'ILIKE', $like)
                ->orWhere('city', 'ILIKE', $like));
        }

        if (AdminAccess::isDed($admin)) {
            $statusFilter = $request->query('status');
            if ($statusFilter === 'pending_circle_fee') {
                $query->where('circle_join_requests.status', CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE);
                if (Schema::hasColumn('circle_join_requests', 'fee_paid_at')) {
                    $query->whereNull('circle_join_requests.fee_paid_at');
                }
            } elseif (in_array($statusFilter, [CircleJoinRequest::STATUS_PENDING_CD_APPROVAL, CircleJoinRequest::STATUS_PENDING_ID_APPROVAL], true)) {
                $query->where('circle_join_requests.status', $statusFilter)
                    ->where(function ($q) {
                        $q->whereNull('circle_join_requests.ded_approval_status')
                          ->orWhere('circle_join_requests.ded_approval_status', '!=', 'approved')
                          ->orWhereNull('circle_join_requests.ded_approved_at');
                    });
            } else {
                $query->whereIn('circle_join_requests.status', [
                        CircleJoinRequest::STATUS_PENDING_CD_APPROVAL,
                        CircleJoinRequest::STATUS_PENDING_ID_APPROVAL,
                    ])
                    ->where(function ($q) {
                        $q->whereNull('circle_join_requests.ded_approval_status')
                          ->orWhere('circle_join_requests.ded_approval_status', '!=', 'approved')
                          ->orWhereNull('circle_join_requests.ded_approved_at');
                    });
            }
            $query->when($request->query('circle_id'), fn ($q, $v) => $q->where('circle_id', $v))
                ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('requested_at', '>=', $v))
                ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('requested_at', '<=', $v));
        } else {
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
        }

        $requests = $query->latest('created_at')->paginate(25)->appends($request->query());

        $requests->getCollection()->transform(function (CircleJoinRequest $joinRequest) use ($admin, $actor) {
            $joinRequest->setAttribute('can_approve_cd', $this->canApproveCd($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_reject_cd', $this->canApproveCd($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_approve_id', $this->canApproveId($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_reject_id', $this->canApproveId($admin, $actor, $joinRequest));
            $joinRequest->setAttribute('can_approve_ded', $this->canApproveDed($admin, $actor, $joinRequest));

            return $joinRequest;
        });

        return view('admin.circle_join_requests.index', [
            'requests' => $requests,
            'circles' => $this->circleOptions($admin),
            'filters' => $request->only(['search', 'circle_id', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(string $id): View
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);
        $this->reconcileDedApprovalWorkflowState($id);

        $record = CircleJoinRequest::query()->with(['user', 'circle', 'cdApprovedBy', 'cdRejectedBy', 'idApprovedBy', 'idRejectedBy', 'dedApprovedBy'])->findOrFail($id);
        abort_unless($this->canAccessRecord($admin, $actor, $record), 403);

        $selectedCategoryIds = $this->resolveSelectedCategoryIds($record);

        return view('admin.circle_join_requests.show', [
            'record' => $record,
            'canApproveCd' => $this->canApproveCd($admin, $actor, $record),
            'canApproveId' => $this->canApproveId($admin, $actor, $record),
            'canApproveDed' => $this->canApproveDed($admin, $actor, $record),
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

    public function approveDed(Request $request, string $id): RedirectResponse
    {
        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor) use ($request): void {
            abort_unless($this->canApproveDed($admin, $actor, $record), 403);
            $this->approveRequestByDed($record, $admin, $actor, $request->input('remarks'));
        }, 'DED approval completed successfully.');
    }

    public function rejectDed(Request $request, string $id): RedirectResponse
    {
        $request->validate(['remarks' => ['required', 'string', 'max:1000']]);

        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor) use ($request): void {
            abort_unless($this->canApproveDed($admin, $actor, $record), 403);
            
            DB::transaction(function () use ($record, $request, $actor): void {
                $req = CircleJoinRequest::query()->lockForUpdate()->findOrFail($record->id);
                $req->ded_approval_status = 'rejected';
                $req->status = CircleJoinRequest::STATUS_CANCELLED;
                
                $notes = (array) $req->notes;
                $notes['ded_rejection_reason'] = $request->input('remarks');
                $req->notes = $notes;
                
                $req->save();
            });
        }, 'DED rejection completed successfully.');
    }

    public function rejectId(Request $request, string $id): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runAction($id, function (CircleJoinRequest $record, $admin, $actor) use ($request): void {
            abort_unless($this->canApproveId($admin, $actor, $record), 403);
            $this->rejectRequest($record, $actor, (string) $request->input('reason'));
        });
    }

    private function runAction(string $id, callable $callback, string $successMessage = 'Action completed successfully.'): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        $actor = AdminAccess::resolveAppUser($admin);
        abort_unless($admin !== null, 403);
        if (! AdminAccess::isDed($admin) && ! AdminAccess::isGlobalAdmin($admin)) {
            abort_unless($actor !== null, 403);
        }

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

            return back()->with('success', $successMessage);
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

                if ($this->hasDedApprovalColumns() && (string) ($request->ded_approval_status ?? 'pending') === 'pending') {
                    $request->ded_approval_status = 'approved';
                    $request->ded_approved_by = $actor->id;
                    $request->ded_approved_at = now();
                }
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

    private function approveRequestByDed(CircleJoinRequest $record, $admin, $actor, ?string $remarks = null): void
    {
        if (! $this->hasDedApprovalColumns()) {
            throw ValidationException::withMessages([
                'ded_approval' => ['DED approval tracking columns are missing. Please run the provided manual SQL first.'],
            ]);
        }

        DB::transaction(function () use ($record, $admin, $actor, $remarks): void {
            $request = CircleJoinRequest::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless($this->canAccessRecord($admin, $actor, $request), 403);

            if (! in_array((string) $request->status, $this->dedApprovableStatuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => ['DED approval is only available while a request is awaiting DED review.'],
                ]);
            }

            if ((string) ($request->ded_approval_status ?? 'pending') === 'approved') {
                throw ValidationException::withMessages([
                    'ded_approval' => ['This request is already DED approved.'],
                ]);
            }

            $request->ded_approval_status = 'approved';
            $request->ded_approved_by = $actor?->id;
            $request->ded_approved_at = now();
            $request->status = CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE;
            if (Schema::hasColumn('circle_join_requests', 'fee_marked_at') && ! $request->fee_marked_at) {
                $request->fee_marked_at = now();
            }

            if ($remarks !== null && trim($remarks) !== '') {
                $notes = (array) $request->notes;
                $notes['ded_approval_remarks'] = trim($remarks);
                $request->notes = $notes;
            }

            $request->save();

            $this->writeDedApprovalAuditLog($admin, $actor, $request);

            Log::info('circle_join_request.ded_approved', [
                'request_id' => $request->id,
                'status' => $request->status,
                'actor_user_id' => $actor->id ?? null,
                'admin_user_id' => $admin->id ?? null,
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

    private function circleOptions($admin)
    {
        $query = Circle::query()->orderBy('name');

        if ($this->industryScope->isIndustryDirector($admin)) {
            $circleIds = $this->industryScope->circleIdsForAdmin($admin);
            $query->when($circleIds !== [], fn ($q) => $q->whereIn('id', $circleIds), fn ($q) => $q->whereRaw('1 = 0'));
        }

        return $query->get(['id', 'name']);
    }

    private function canAccessRecord($admin, $actor, CircleJoinRequest $record): bool
    {
        if ($this->industryScope->isIndustryDirector($admin)) {
            return in_array((string) $record->circle_id, $this->industryScope->circleIdsForAdmin($admin), true);
        }

        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        if (AdminAccess::isDed($admin)) {
            $allowedCircleIds = AdminCircleScope::getDedCircleIds($admin);
            return in_array((string) $record->circle_id, $allowedCircleIds, true);
        }

        if (! $actor) {
            return false;
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
        if (! $actor) {
            return false;
        }

        if (! $this->canAccessRecord($admin, $actor, $record)) {
            return false;
        }

        if ($record->status !== CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
            return false;
        }

        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        if ($this->industryScope->isIndustryDirector($admin)) {
            return false;
        }

        return (string) $record->circle?->director_user_id === (string) $actor->id;
    }

    private function canApproveId($admin, $actor, CircleJoinRequest $record): bool
    {
        if (! $actor) {
            return false;
        }

        if (! $this->canAccessRecord($admin, $actor, $record)) {
            return false;
        }

        if ($record->status !== CircleJoinRequest::STATUS_PENDING_ID_APPROVAL) {
            return false;
        }

        if (AdminAccess::isGlobalAdmin($admin)) {
            return true;
        }

        if ($this->industryScope->isIndustryDirector($admin)) {
            return true;
        }

        return (string) $record->circle?->industry_director_user_id === (string) $actor->id;
    }

    private function canApproveDed($admin, $actor, CircleJoinRequest $record): bool
    {
        if (! AdminAccess::isDed($admin)) {
            return false;
        }

        if (! $this->hasDedApprovalColumns()) {
            return false;
        }

        if (! $this->canAccessRecord($admin, $actor, $record)) {
            return false;
        }

        if (! in_array((string) $record->status, $this->dedApprovableStatuses(), true)) {
            return false;
        }

        return (string) ($record->ded_approval_status ?? 'pending') !== 'approved';
    }

    private function dedApprovableStatuses(): array
    {
        return [
            CircleJoinRequest::STATUS_PENDING_CD_APPROVAL,
            CircleJoinRequest::STATUS_PENDING_ID_APPROVAL,
        ];
    }

    private function reconcileDedApprovalWorkflowState(?string $requestId = null): void
    {
        if (! $this->hasDedApprovalColumns()) {
            return;
        }

        $now = now();
        $approvedStatuses = [
            CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE,
            CircleJoinRequest::STATUS_CIRCLE_MEMBER,
            CircleJoinRequest::STATUS_PAID,
        ];

        $approvedQuery = DB::table('circle_join_requests')
            ->whereIn('status', $approvedStatuses)
            ->where(function ($query): void {
                $query->whereNull('ded_approval_status')
                    ->orWhere('ded_approval_status', '')
                    ->orWhere('ded_approval_status', 'pending');
            });

        if ($requestId) {
            $approvedQuery->where('id', $requestId);
        }

        $approvedPayload = [
            'ded_approval_status' => 'approved',
            'ded_approved_at' => DB::raw('COALESCE(ded_approved_at, id_approved_at, cd_approved_at, fee_marked_at, updated_at, NOW())'),
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('circle_join_requests', 'ded_approved_by')) {
            $approvedPayload['ded_approved_by'] = DB::raw('COALESCE(ded_approved_by, id_approved_by, cd_approved_by)');
        }

        $approvedQuery->update($approvedPayload);

        $rejectedQuery = DB::table('circle_join_requests')
            ->whereIn('status', [
                CircleJoinRequest::STATUS_REJECTED_BY_CD,
                CircleJoinRequest::STATUS_REJECTED_BY_ID,
                CircleJoinRequest::STATUS_CANCELLED,
            ])
            ->where(function ($query): void {
                $query->whereNull('ded_approval_status')
                    ->orWhere('ded_approval_status', '')
                    ->orWhere('ded_approval_status', 'pending');
            });

        if ($requestId) {
            $rejectedQuery->where('id', $requestId);
        }

        $rejectedQuery->update([
            'ded_approval_status' => 'rejected',
            'updated_at' => $now,
        ]);
    }

    private function hasDedApprovalColumns(): bool
    {
        return Schema::hasTable('circle_join_requests')
            && Schema::hasColumn('circle_join_requests', 'ded_approval_status')
            && Schema::hasColumn('circle_join_requests', 'ded_approved_by')
            && Schema::hasColumn('circle_join_requests', 'ded_approved_at');
    }

    private function writeDedApprovalAuditLog($admin, $actor, CircleJoinRequest $record): void
    {
        if (! Schema::hasTable('admin_audit_logs')) {
            return;
        }

        try {
            AdminAuditLog::query()->create([
                'id' => (string) Str::uuid(),
                'admin_user_id' => $actor->id ?? null,
                'action' => 'circle_join_request.ded_approved',
                'target_table' => 'circle_join_requests',
                'target_id' => $record->id,
                'details' => [
                    'ded_admin_user_id' => $admin->id ?? null,
                    'approver_user_id' => $actor->id ?? null,
                    'approval_status' => $record->ded_approval_status,
                    'approved_at' => optional($record->ded_approved_at)->toISOString(),
                    'workflow_status' => $record->status,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('circle_join_request.ded_approval_audit_log_failed', [
                'request_id' => $record->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
