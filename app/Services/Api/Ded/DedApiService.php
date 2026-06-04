<?php

namespace App\Services\Api\Ded;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\BusinessDeal;
use App\Models\Circle;
use App\Models\CircleJoinRequest;
use App\Models\CoinClaimRequest;
use App\Models\CoinLedger;
use App\Models\Event;
use App\Models\EventRegistrationRequest;
use App\Models\Impact;
use App\Models\P2pMeeting;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DedApiService
{
    public function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->attributes->get('ded_admin');

        return $admin;
    }

    public function location(Request $request): array
    {
        return (array) $request->attributes->get('ded_location', []);
    }

    public function actor(Request $request): User
    {
        $actor = $request->attributes->get('ded_actor') ?: $request->user();

        if ($actor instanceof User) {
            return $actor;
        }

        abort(403, 'DED API token is not linked to an app user for this action.');
    }

    public function success($data = [], string $message = 'OK', array $meta = [], int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => (object) $meta,
        ], $status);
    }

    public function error(string $message, int $status = 400, array $errors = [])
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }

    public function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', $default)));
    }

    public function circlesQuery(AdminUser $admin): EloquentBuilder
    {
        $query = Circle::query()->withCount(['members as approved_members_count' => function ($q): void {
            $q->where('status', 'approved')->whereNull('deleted_at');
        }]);
        AdminCircleScope::applyToCirclesQuery($query, $admin);

        return $query;
    }

    public function usersQuery(AdminUser $admin): EloquentBuilder
    {
        $query = User::query()->with(['city', 'activeCircle:id,name']);
        AdminCircleScope::applyToUsersQuery($query, $admin);

        return $query;
    }

    public function applyCircleFilterToUsers(EloquentBuilder $query, ?string $circleId): void
    {
        if (! $circleId || $circleId === 'all') {
            return;
        }

        $query->whereExists(function ($subQuery) use ($circleId): void {
            $subQuery->selectRaw(1)
                ->from('circle_members as ded_api_circle_members')
                ->whereColumn('ded_api_circle_members.user_id', 'users.id')
                ->where('ded_api_circle_members.status', 'approved')
                ->whereNull('ded_api_circle_members.deleted_at')
                ->where('ded_api_circle_members.circle_id', $circleId);
        });
    }

    public function assertCircleInScope(AdminUser $admin, ?string $circleId): void
    {
        if (! $circleId || $circleId === 'all') {
            return;
        }

        $query = Circle::query()->whereKey($circleId);
        AdminCircleScope::applyToCirclesQuery($query, $admin);

        abort_unless($query->exists(), 403, 'Circle is outside the assigned DED district.');
    }

    public function assertUserInScope(AdminUser $admin, string $userId): void
    {
        abort_unless(AdminCircleScope::userInScope($admin, $userId), 403, 'Peer is outside the assigned DED district.');
    }

    public function applyDateFilters($query, Request $request, string $column = 'created_at'): void
    {
        $query->when($request->query('date_from'), fn ($q, $v) => $q->whereDate($column, '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate($column, '<=', $v));
    }

    public function applyActivityScope($query, AdminUser $admin, string $primaryColumn, ?string $peerColumn = null, ?string $circleId = null): void
    {
        AdminCircleScope::applyToActivityQuery($query, $admin, $primaryColumn, $peerColumn);

        if ($circleId && $circleId !== 'all') {
            $query->where(function ($circleQuery) use ($primaryColumn, $peerColumn, $circleId): void {
                $this->whereUserInCircle($circleQuery, $primaryColumn, $circleId);

                if ($peerColumn) {
                    $circleQuery->orWhere(function ($peerQuery) use ($peerColumn, $circleId): void {
                        $this->whereUserInCircle($peerQuery, $peerColumn, $circleId);
                    });
                }
            });
        }
    }

    public function whereUserInCircle($query, string $userColumn, string $circleId): void
    {
        $query->whereExists(function ($subQuery) use ($userColumn, $circleId): void {
            $subQuery->selectRaw(1)
                ->from('circle_members as ded_api_activity_circle_members')
                ->whereColumn('ded_api_activity_circle_members.user_id', $userColumn)
                ->where('ded_api_activity_circle_members.status', 'approved')
                ->whereNull('ded_api_activity_circle_members.deleted_at')
                ->where('ded_api_activity_circle_members.circle_id', $circleId);
        });
    }

    public function dashboard(AdminUser $admin, Request $request): array
    {
        $circleId = trim((string) $request->query('circle_id', ''));
        if ($circleId === 'all') {
            $circleId = '';
        }
        $this->assertCircleInScope($admin, $circleId ?: null);

        $users = $this->usersQuery($admin);
        $this->applyCircleFilterToUsers($users, $circleId ?: null);

        $circles = $this->circlesQuery($admin);
        $districtCircles = (clone $circles)->orderBy('name')->get(['id', 'name', 'status', 'city']);

        $latest = (clone $users)->latest('created_at')->limit(8)->get($this->userColumns())->map(fn (User $user) => $this->userSummary($user))->values();

        return [
            'total_district_peers' => (int) (clone $users)->count(),
            'total_district_circles' => $circleId ? 1 : (int) $districtCircles->count(),
            'total_referrals' => $this->activityCount($admin, 'referrals', 'from_user_id', 'to_user_id', true, $circleId ?: null),
            'total_requirements' => $this->activityCount($admin, 'requirements', 'user_id', null, false, $circleId ?: null),
            'total_testimonials' => $this->activityCount($admin, 'testimonials', 'from_user_id', 'to_user_id', true, $circleId ?: null),
            'total_business_deals' => $this->activityCount($admin, 'business_deals', 'from_user_id', 'to_user_id', true, $circleId ?: null),
            'total_p2p_meetings' => $this->activityCount($admin, 'p2p_meetings', 'initiator_user_id', 'peer_user_id', true, $circleId ?: null),
            'total_coins_earned' => $this->coinsEarned($admin, $circleId ?: null),
            'pending_requests' => array_sum($this->pendingRequestCounts($admin, $circleId ?: null)),
            'latest_district_peers' => $latest,
            'circles' => $districtCircles,
            'selected_circle_id' => $circleId ?: null,
        ];
    }

    public function activityCount(AdminUser $admin, string $table, string $primaryColumn, ?string $peerColumn = null, bool $hasIsDeleted = false, ?string $circleId = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $primaryColumn)) {
            return 0;
        }

        $query = DB::table("{$table} as activity");
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('activity.deleted_at');
        }
        if ($hasIsDeleted && Schema::hasColumn($table, 'is_deleted')) {
            $query->where('activity.is_deleted', false);
        }
        $peer = $peerColumn && Schema::hasColumn($table, $peerColumn) ? "activity.{$peerColumn}" : null;
        $this->applyActivityScope($query, $admin, "activity.{$primaryColumn}", $peer, $circleId);

        return (int) $query->count();
    }

    public function coinsEarned(AdminUser $admin, ?string $circleId = null): int
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'coins_balance')) {
            return 0;
        }

        $query = $this->usersQuery($admin);
        $this->applyCircleFilterToUsers($query, $circleId);

        return (int) $query->sum('users.coins_balance');
    }

    public function pendingRequestCounts(AdminUser $admin, ?string $circleId = null): array
    {
        return [
            'visitor_registrations' => $this->visitorRegistrationsQuery($admin, $circleId)->count(),
            'event_joining_requests' => Schema::hasTable((new EventRegistrationRequest())->getTable()) ? $this->eventJoiningRequestsQuery($admin, $circleId)->count() : 0,
            'coin_claims' => $this->coinClaimsQuery($admin, $circleId)->count(),
            'circle_joining_requests' => $this->circleJoinRequestsQuery($admin, $circleId)->count(),
            'pending_impacts' => $this->impactsQuery($admin, $circleId)->where('status', 'pending')->count(),
        ];
    }

    public function userColumns(): array
    {
        return ['id', 'display_name', 'first_name', 'last_name', 'email', 'phone', 'company_name', 'city', 'city_id', 'membership_status', 'status', 'coins_balance', 'created_at', 'active_circle_id'];
    }

    public function userSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'email' => $user->email,
            'phone' => $user->phone,
            'company' => $user->company_name,
            'city' => $this->userCityLabel($user),
            'membership_status' => $user->membership_status,
            'status' => $user->status ?? null,
            'coins_balance' => (int) ($user->coins_balance ?? 0),
            'active_circle' => $user->activeCircle ? ['id' => $user->activeCircle->id, 'name' => $user->activeCircle->name] : null,
            'created_at' => optional($user->created_at)->toISOString(),
        ];
    }


    private function userCityLabel(User $user): ?string
    {
        $cityRelation = $user->relationLoaded('city') ? $user->getRelation('city') : null;

        return $cityRelation?->name ?: ($user->getAttribute('city') ?: null);
    }

    public function circlesIndex(AdminUser $admin, Request $request): LengthAwarePaginator
    {
        $query = $this->circlesQuery($admin)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), function ($q, $term): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $term) . '%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('name', 'ILIKE', $like)
                        ->orWhere('city', 'ILIKE', $like)
                        ->orWhere('description', 'ILIKE', $like);
                });
            });

        return $query->orderBy('name')->paginate($this->perPage($request));
    }

    public function peersIndex(AdminUser $admin, Request $request): LengthAwarePaginator
    {
        $query = $this->usersQuery($admin);
        $this->applyCircleFilterToUsers($query, $request->query('circle_id'));

        $query->when($request->query('membership_status'), fn ($q, $v) => $q->where('membership_status', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), function ($q, $term): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $term) . '%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('display_name', 'ILIKE', $like)
                        ->orWhere('first_name', 'ILIKE', $like)
                        ->orWhere('last_name', 'ILIKE', $like)
                        ->orWhere('email', 'ILIKE', $like)
                        ->orWhere('phone', 'ILIKE', $like)
                        ->orWhere('company_name', 'ILIKE', $like)
                        ->orWhere('city', 'ILIKE', $like);
                });
            });

        return $query->latest('created_at')->paginate($this->perPage($request));
    }

    public function activitySummary(AdminUser $admin, Request $request): array
    {
        $circleId = $request->query('circle_id');
        $this->assertCircleInScope($admin, $circleId);

        $users = $this->peersIndex($admin, $request);
        $top = $this->topDistrictPeers($admin, $circleId);

        return [
            'items' => collect($users->items())->map(function (User $user) use ($admin, $circleId): array {
                return array_merge($this->userSummary($user), [
                    'testimonials_count' => $this->userActivityCount($admin, 'testimonials', 'from_user_id', 'to_user_id', (string) $user->id, $circleId),
                    'referrals_count' => $this->userActivityCount($admin, 'referrals', 'from_user_id', 'to_user_id', (string) $user->id, $circleId),
                    'business_deals_count' => $this->userActivityCount($admin, 'business_deals', 'from_user_id', 'to_user_id', (string) $user->id, $circleId),
                    'p2p_meetings_count' => $this->userActivityCount($admin, 'p2p_meetings', 'initiator_user_id', 'peer_user_id', (string) $user->id, $circleId),
                    'requirements_count' => $this->userActivityCount($admin, 'requirements', 'user_id', null, (string) $user->id, $circleId),
                    'become_a_leader_count' => $this->tableUserCount($admin, 'leader_interest_submissions', 'user_id', (string) $user->id, $circleId),
                    'recommend_a_peer_count' => $this->tableUserCount($admin, 'peer_recommendations', 'user_id', (string) $user->id, $circleId),
                    'visitor_registrations_count' => $this->tableUserCount($admin, 'visitor_registrations', 'user_id', (string) $user->id, $circleId),
                ]);
            })->values(),
            'top_5_district_peers' => $top,
            'pagination' => $this->paginationMeta($users)['pagination'],
        ];
    }

    public function topDistrictPeers(AdminUser $admin, ?string $circleId = null): array
    {
        $users = $this->usersQuery($admin);
        $this->applyCircleFilterToUsers($users, $circleId);

        return $users->limit(100)->get($this->userColumns())->map(function (User $user) use ($admin, $circleId): array {
            $score = $this->userActivityCount($admin, 'testimonials', 'from_user_id', 'to_user_id', (string) $user->id, $circleId)
                + $this->userActivityCount($admin, 'referrals', 'from_user_id', 'to_user_id', (string) $user->id, $circleId)
                + $this->userActivityCount($admin, 'requirements', 'user_id', null, (string) $user->id, $circleId)
                + $this->userActivityCount($admin, 'business_deals', 'from_user_id', 'to_user_id', (string) $user->id, $circleId)
                + $this->userActivityCount($admin, 'p2p_meetings', 'initiator_user_id', 'peer_user_id', (string) $user->id, $circleId)
                + $this->tableUserCount($admin, 'leader_interest_submissions', 'user_id', (string) $user->id, $circleId)
                + $this->tableUserCount($admin, 'peer_recommendations', 'user_id', (string) $user->id, $circleId)
                + $this->tableUserCount($admin, 'visitor_registrations', 'user_id', (string) $user->id, $circleId);

            return array_merge($this->userSummary($user), ['performance_score' => $score]);
        })->sortByDesc('performance_score')->take(5)->values()->all();
    }

    public function userActivityCount(AdminUser $admin, string $table, string $primaryColumn, ?string $peerColumn, string $userId, ?string $circleId = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table("{$table} as activity")->where(function ($q) use ($primaryColumn, $peerColumn, $userId): void {
            $q->where("activity.{$primaryColumn}", $userId);
            if ($peerColumn && Schema::hasColumn($table, $peerColumn)) {
                $q->orWhere("activity.{$peerColumn}", $userId);
            }
        });
        $this->applyActivityScope($query, $admin, "activity.{$primaryColumn}", $peerColumn ? "activity.{$peerColumn}" : null, $circleId);

        return (int) $query->count();
    }

    public function tableUserCount(AdminUser $admin, string $table, string $column, string $userId, ?string $circleId = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }
        $query = DB::table("{$table} as activity")->where("activity.{$column}", $userId);
        $this->applyActivityScope($query, $admin, "activity.{$column}", null, $circleId);

        return (int) $query->count();
    }

    public function activityQuery(string $type, AdminUser $admin, Request $request): EloquentBuilder
    {
        [$model, $primary, $peer, $relations, $dateColumn] = $this->activityMap($type);
        /** @var EloquentBuilder $query */
        $query = $model::query()->with($relations);
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            // SoftDeletes global scope already applies.
        }
        $this->applyActivityScope($query, $admin, $primary, $peer, $request->query('circle_id'));
        $this->applyDateFilters($query, $request, $dateColumn);
        $this->applyGenericSearch($query, $request->query('search'));
        if ($request->query('status') && Schema::hasColumn((new $model())->getTable(), 'status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('referral_type') && $type === 'referrals') {
            $query->where('referral_type', $request->query('referral_type'));
        }
        if ($request->query('category') && $type === 'requirements') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('category')) . '%';
            $query->where(function ($categoryQuery) use ($like): void {
                $categoryQuery->whereRaw('category_filter::text ILIKE ?', [$like])
                    ->orWhereRaw('region_filter::text ILIKE ?', [$like]);
            });
        }
        if ($request->boolean('has_media') && $type === 'testimonials') {
            $query->whereNotNull('media');
        }

        return $query;
    }

    public function activityMap(string $type): array
    {
        return match ($type) {
            'testimonials' => [Testimonial::class, 'testimonials.from_user_id', 'testimonials.to_user_id', ['fromUser', 'toUser'], 'created_at'],
            'requirements' => [Requirement::class, 'requirements.user_id', null, ['user'], 'created_at'],
            'referrals' => [Referral::class, 'referrals.from_user_id', 'referrals.to_user_id', ['fromUser', 'toUser'], 'created_at'],
            'p2p-meetings' => [P2pMeeting::class, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id', ['initiator', 'peer'], 'meeting_date'],
            'business-deals' => [BusinessDeal::class, 'business_deals.from_user_id', 'business_deals.to_user_id', ['fromUser', 'toUser'], 'deal_date'],
            default => abort(404, 'Unsupported activity type.'),
        };
    }

    public function applyGenericSearch(EloquentBuilder $query, mixed $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        $model = $query->getModel();
        $table = $model->getTable();
        $query->where(function ($inner) use ($like, $model, $table): void {
            foreach (['content', 'subject', 'description', 'remarks', 'comment', 'referral_of', 'phone', 'email', 'request_reason', 'admin_note', 'visitor_full_name', 'visitor_mobile', 'visitor_email', 'visitor_city', 'visitor_business', 'activity_code', 'story_to_share', 'additional_remarks', 'review_remarks'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $inner->orWhere($column, 'ILIKE', $like);
                }
            }

            foreach (['fromUser', 'toUser', 'user', 'initiator', 'peer', 'impactedPeer', 'invitedByUser'] as $relation) {
                if (method_exists($model, $relation)) {
                    $inner->orWhereHas($relation, fn ($q) => $q->where('display_name', 'ILIKE', $like)
                        ->orWhere('first_name', 'ILIKE', $like)
                        ->orWhere('last_name', 'ILIKE', $like)
                        ->orWhere('email', 'ILIKE', $like)
                        ->orWhere('phone', 'ILIKE', $like)
                        ->orWhere('company_name', 'ILIKE', $like));
                }
            }

            if (method_exists($model, 'event')) {
                $inner->orWhereHas('event', fn ($q) => $q->where('title', 'ILIKE', $like));
            }
            if (method_exists($model, 'circle')) {
                $inner->orWhereHas('circle', fn ($q) => $q->where('name', 'ILIKE', $like));
            }
        });
    }

    public function coinClaimsQuery(AdminUser $admin, ?string $circleId = null): EloquentBuilder
    {
        $query = CoinClaimRequest::query()->with('user');
        $this->applyActivityScope($query, $admin, 'coin_claim_requests.user_id', null, $circleId);

        return $query;
    }

    public function coinLedgerQuery(AdminUser $admin, Request $request): EloquentBuilder
    {
        $query = CoinLedger::query()->with('user');
        $this->applyActivityScope($query, $admin, 'coins_ledger.user_id', null, null);
        $query->when($request->query('user_id'), function ($q, $userId) use ($admin): void {
            $this->assertUserInScope($admin, (string) $userId);
            $q->where('user_id', $userId);
        });
        $this->applyDateFilters($query, $request, 'created_at');

        return $query;
    }

    public function visitorRegistrationsQuery(AdminUser $admin, ?string $circleId = null): EloquentBuilder
    {
        $query = VisitorRegistration::query()->with(['user', 'invitedByUser']);
        $this->applyActivityScope($query, $admin, 'visitor_registrations.user_id', 'visitor_registrations.invited_by_user_id', $circleId);

        return $query;
    }

    public function eventJoiningRequestsQuery(AdminUser $admin, ?string $circleId = null): EloquentBuilder
    {
        if (! Schema::hasTable((new EventRegistrationRequest())->getTable())) {
            abort(500, 'Event joining request table is not available.');
        }

        $query = EventRegistrationRequest::query()->with(['user', 'event.circle', 'occurrence', 'registration', 'approvedBy', 'rejectedBy']);
        $this->applyEventRegistrationRequestScope($query, $admin);
        if ($circleId && $circleId !== 'all') {
            $query->where(function ($q) use ($circleId): void {
                $q->where('event_circle_id', $circleId)
                    ->orWhereHas('event', fn ($eventQuery) => $eventQuery->where('circle_id', $circleId));
            });
        }

        return $query;
    }

    public function applyEventRegistrationRequestScope(EloquentBuilder $query, AdminUser $admin): void
    {
        $query->where(function ($scope) use ($admin): void {
            $scope->where(function ($userScope) use ($admin): void {
                AdminCircleScope::applyDedDistrictScope($userScope, $admin, 'event_registration_requests.user_id');
            })->orWhereHas('event', function ($eventQuery) use ($admin): void {
                AdminCircleScope::applyToEventsQuery($eventQuery, $admin);
            });
        });
    }

    public function circleJoinRequestsQuery(AdminUser $admin, ?string $circleId = null): EloquentBuilder
    {
        $query = CircleJoinRequest::query()->with(['user', 'circle', 'dedApprovedBy']);
        $query->visibleToAdminUser($admin);
        if ($circleId && $circleId !== 'all') {
            $query->where('circle_id', $circleId);
        }

        return $query;
    }

    public function impactsQuery(AdminUser $admin, ?string $circleId = null): EloquentBuilder
    {
        $query = Impact::query()->with(['user', 'impactedPeer', 'approvedBy', 'rejectedBy']);
        $this->applyActivityScope($query, $admin, 'impacts.user_id', 'impacts.impacted_peer_id', $circleId);

        return $query;
    }

    public function filterPendingQuery(EloquentBuilder $query, Request $request, string $type): EloquentBuilder
    {
        $query->when($request->query('status'), fn ($q, $v) => $q->where('status', $v));
        $this->applyDateFilters($query, $request);
        if ($request->query('circle_id')) {
            // Circle filter is applied by the source query for modules where the column exists.
        }
        if ($request->query('event_id') && $type === 'event_joining_requests') {
            $query->where('event_id', $request->query('event_id'));
        }
        $this->applyGenericSearch($query, $request->query('search'));

        return $query;
    }

    public function approveOrReject(string $type, string $id, string $action, Request $request)
    {
        $admin = $this->admin($request);
        $actor = $this->actor($request);
        $note = (string) ($request->input('reason') ?: $request->input('admin_note') ?: $request->input('remarks') ?: '');

        return match ($type) {
            'visitor_registrations' => $this->reviewVisitor($admin, $id, $action, $note),
            'event_joining_requests' => $this->reviewEventJoining($admin, $actor, $id, $action, $note),
            'coin_claims' => $this->reviewCoinClaim($admin, $id, $action, $note),
            'circle_joining_requests' => $this->reviewCircleJoin($admin, $actor, $id, $action, $note),
            'pending_impacts' => $this->reviewImpact($admin, $id, $action, $note),
            default => abort(404, 'Unsupported pending request type.'),
        };
    }

    private function reviewVisitor(AdminUser $admin, string $id, string $action, string $note)
    {
        $record = $this->visitorRegistrationsQuery($admin)->findOrFail($id);
        $record->status = $action === 'approve' ? 'approved' : 'rejected';
        if (Schema::hasColumn('visitor_registrations', 'reviewed_by_admin_user_id')) {
            $record->reviewed_by_admin_user_id = $admin->id;
        }
        if (Schema::hasColumn('visitor_registrations', 'reviewed_at')) {
            $record->reviewed_at = now();
        }
        $record->save();
        $this->audit($admin, AdminAccess::resolveAppUser($admin) ?: $record->user, 'visitor_registration.' . $action, $record->id, ['status' => $record->status]);

        return $record->fresh(['user', 'invitedByUser']);
    }

    private function reviewEventJoining(AdminUser $admin, User $actor, string $id, string $action, string $note)
    {
        $record = $this->eventJoiningRequestsQuery($admin)->findOrFail($id);
        $record->status = $action === 'approve' ? 'approved' : 'rejected';
        $record->admin_note = $note ?: ($action === 'approve' ? 'Approved by DED.' : 'Rejected by DED.');
        if ($action === 'approve') {
            $record->approved_by_user_id = $actor->id;
            $record->approved_at = now();
        } else {
            $record->rejected_by_user_id = $actor->id;
            $record->rejected_at = now();
        }
        $record->save();
        $this->audit($admin, $actor, 'event_joining_request.' . $action, $record->id, ['status' => $record->status]);

        return $record->fresh(['user', 'event.circle', 'occurrence']);
    }

    private function reviewCoinClaim(AdminUser $admin, string $id, string $action, string $note)
    {
        $record = $this->coinClaimsQuery($admin)->findOrFail($id);
        $record->status = $action === 'approve' ? 'approved' : 'rejected';
        if ($action === 'approve' && Schema::hasColumn('coin_claim_requests', 'approved_at')) {
            $record->approved_at = now();
        }
        if ($action === 'reject' && Schema::hasColumn('coin_claim_requests', 'rejected_at')) {
            $record->rejected_at = now();
        }
        if (Schema::hasColumn('coin_claim_requests', 'admin_notes')) {
            $record->admin_notes = $note;
        }
        $record->save();
        $this->audit($admin, AdminAccess::resolveAppUser($admin) ?: $record->user, 'coin_claim.' . $action, $record->id, ['status' => $record->status]);

        return $record->fresh('user');
    }

    private function reviewCircleJoin(AdminUser $admin, User $actor, string $id, string $action, string $note)
    {
        $record = $this->circleJoinRequestsQuery($admin)->findOrFail($id);
        if ($action === 'ded-approve') {
            if ((string) $record->status !== CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
                throw ValidationException::withMessages(['status' => ['DED approval is only available while pending CD approval.']]);
            }
            $record->ded_approval_status = 'approved';
            $record->ded_approved_by = $actor->id;
            $record->ded_approved_at = now();
            $record->status = CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE;
            if (Schema::hasColumn('circle_join_requests', 'fee_marked_at')) {
                $record->fee_marked_at = $record->fee_marked_at ?: now();
            }
        } else {
            $record->ded_approval_status = 'rejected';
            $record->status = CircleJoinRequest::STATUS_REJECTED_BY_CD;
            if (Schema::hasColumn('circle_join_requests', 'cd_rejection_reason')) {
                $record->cd_rejection_reason = $note ?: 'Rejected by DED.';
            }
        }
        $record->save();
        $this->audit($admin, $actor, 'circle_join_request.ded_' . $action, $record->id, ['status' => $record->status]);

        return $record->fresh(['user', 'circle', 'dedApprovedBy']);
    }

    private function reviewImpact(AdminUser $admin, string $id, string $action, string $note)
    {
        $record = $this->impactsQuery($admin)->findOrFail($id);
        $record->status = $action === 'approve' ? 'approved' : 'rejected';
        if ($action === 'approve') {
            $record->approved_by = $admin->id;
            $record->approved_at = now();
        } else {
            $record->rejected_by = $admin->id;
            $record->rejected_at = now();
        }
        if (Schema::hasColumn('impacts', 'review_remarks')) {
            $record->review_remarks = $note;
        }
        $record->save();
        $this->audit($admin, AdminAccess::resolveAppUser($admin) ?: $record->user, 'impact.' . $action, $record->id, ['status' => $record->status]);

        return $record->fresh(['user', 'impactedPeer']);
    }

    private function audit(AdminUser $admin, User $actor, string $action, string $auditableId, array $meta = []): void
    {
        if (! Schema::hasTable('admin_audit_logs')) {
            return;
        }

        AdminAuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $actor->id,
            'action' => $action,
            'target_table' => Str::before($action, '.'),
            'target_id' => $auditableId,
            'details' => array_merge($meta, [
                'ded_admin_user_id' => $admin->id,
                'actor_user_id' => $actor->id,
            ]),
            'created_at' => now(),
        ]);
    }
}
