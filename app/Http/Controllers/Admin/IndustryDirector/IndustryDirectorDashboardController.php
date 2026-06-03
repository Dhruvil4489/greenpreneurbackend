<?php

namespace App\Http\Controllers\Admin\IndustryDirector;

use App\Http\Controllers\Controller;
use App\Models\CircleJoinRequest;
use App\Models\CoinLedger;
use App\Models\Industry;
use App\Models\LifeImpactHistory;
use App\Models\Post;
use App\Models\User;
use App\Services\Admin\IndustryScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class IndustryDirectorDashboardController extends Controller
{
    public function index(IndustryScopeService $industryScope): View
    {
        $admin = Auth::guard('admin')->user();
        $assignedIndustryId = $industryScope->assignedIndustryIdForAdmin((string) $admin->id);
        $industryIds = $industryScope->industryIdsForAdmin((string) $admin->id);
        $memberIds = $industryScope->memberIdsForAdmin($admin);
        $circleIds = $industryScope->circleIdsForIndustryIds($industryIds);
        $industry = $assignedIndustryId ? Industry::query()->find($assignedIndustryId) : null;

        $metrics = [
            'total_industry_members' => count($memberIds),
            'active_members' => $this->scopedUsersQuery($memberIds)
                ->when(Schema::hasColumn('users', 'status'), fn (Builder $query) => $query->where('status', 'active'))
                ->count(),
            'new_registrations' => $this->scopedUsersQuery($memberIds)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'total_activities' => $this->totalScopedActivities($memberIds),
            'total_posts' => Post::query()
                ->when($memberIds !== [], fn (Builder $query) => $query->whereIn('user_id', $memberIds), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->when(Schema::hasColumn('posts', 'is_deleted'), fn (Builder $query) => $query->where('is_deleted', false))
                ->count(),
            'pending_requests_count' => $this->pendingRequestsCount($memberIds, $circleIds),
            'total_circles' => count($circleIds),
            'total_coins_earned' => CoinLedger::query()
                ->when($memberIds !== [], fn (Builder $query) => $query->whereIn('user_id', $memberIds), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('amount', '>', 0)
                ->sum('amount'),
            'life_impact' => LifeImpactHistory::query()
                ->when($memberIds !== [], fn (Builder $query) => $query->whereIn('user_id', $memberIds), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->sum('life_impacted'),
        ];

        return view('admin.industry-director.dashboard', [
            'industry' => $industry,
            'industryCount' => count($industryIds),
            'metrics' => $metrics,
        ]);
    }

    private function scopedUsersQuery(array $memberIds): Builder
    {
        return User::query()
            ->when($memberIds !== [], fn (Builder $query) => $query->whereIn('id', $memberIds), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when(Schema::hasColumn('users', 'deleted_at'), fn (Builder $query) => $query->whereNull('deleted_at'));
    }

    private function totalScopedActivities(array $memberIds): int
    {
        return array_sum([
            $this->countScopedTable('testimonials', ['from_user_id', 'to_user_id'], $memberIds),
            $this->countScopedTable('requirements', ['user_id'], $memberIds),
            $this->countScopedTable('referrals', ['from_user_id', 'to_user_id'], $memberIds),
            $this->countScopedTable('p2p_meetings', ['initiator_user_id', 'peer_user_id'], $memberIds),
            $this->countScopedTable('business_deals', ['from_user_id', 'to_user_id'], $memberIds),
            $this->countScopedTable('leader_interest_submissions', ['user_id', 'member_id'], $memberIds),
            $this->countScopedTable('peer_recommendations', ['user_id', 'recommender_id', 'recommended_user_id'], $memberIds),
            $this->countScopedTable('collaboration_posts', ['user_id', 'member_id'], $memberIds),
            $this->countScopedTable('visitor_registrations', ['user_id', 'visitor_id', 'created_by', 'invited_by_user_id'], $memberIds),
        ]);
    }

    private function countScopedTable(string $table, array $userColumns, array $memberIds): int
    {
        if ($memberIds === [] || ! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        $query->where(function (QueryBuilder $scope) use ($table, $userColumns, $memberIds): void {
            $hasColumn = false;

            foreach ($userColumns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $scope->orWhereIn($column, $memberIds);
                    $hasColumn = true;
                }
            }

            if (! $hasColumn) {
                $scope->whereRaw('1 = 0');
            }
        });

        if (Schema::hasColumn($table, 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function pendingRequestsCount(array $memberIds, array $circleIds): int
    {
        $count = 0;

        if (Schema::hasTable('circle_join_requests')) {
            $count += CircleJoinRequest::query()
                ->whereIn('status', [CircleJoinRequest::STATUS_PENDING_ID_APPROVAL, CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE])
                ->when($circleIds !== [], fn (Builder $query) => $query->whereIn('circle_id', $circleIds), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->count();
        }

        return $count;
    }
}
