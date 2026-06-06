<?php

namespace App\Services\Api\Ded;

use App\Models\AdminUser;
use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadershipSummaryService
{
    public function __construct(private readonly DistrictScopeService $scope) {}

    /**
     * Get counts and details for all leadership roles in the district.
     */
    public function getLeadershipSummary(AdminUser $admin, ?string $selectedCircleId = null): array
    {
        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $circleSubquery->where('id', $selectedCircleId);
        }

        // 1. Industry Directors
        $industryDirectorQuery = User::query();
        $this->scope->applyUsersScope($industryDirectorQuery, $admin);
        $industryDirectorCount = $industryDirectorQuery
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.industry_director_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->count();

        $recentIndustryDirectors = User::query()
            ->with(['city', 'activeCircle:id,name'])
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.industry_director_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        // 2. Circle Founders
        $circleFounderQuery = User::query();
        $this->scope->applyUsersScope($circleFounderQuery, $admin);
        $circleFounderCount = $circleFounderQuery
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.founder_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->count();

        $recentCircleFounders = User::query()
            ->with(['city', 'activeCircle:id,name'])
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.founder_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        // 3. Circle Directors
        $circleDirectorQuery = User::query();
        $this->scope->applyUsersScope($circleDirectorQuery, $admin);
        $circleDirectorCount = $circleDirectorQuery
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.director_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->count();

        $recentCircleDirectors = User::query()
            ->with(['city', 'activeCircle:id,name'])
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circles')
                    ->whereColumn('circles.director_user_id', 'users.id')
                    ->whereIn('circles.id', $circleSubquery);
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        // 4. Leadership Team
        $leadershipRoles = ['chair', 'vice_chair', 'secretary', 'committee_leader'];

        $leadershipTeamQuery = User::query();
        $this->scope->applyUsersScope($leadershipTeamQuery, $admin);
        $leadershipTeamCount = $leadershipTeamQuery
            ->whereExists(function ($q) use ($circleSubquery, $leadershipRoles) {
                $q->selectRaw(1)
                    ->from('circle_members')
                    ->whereColumn('circle_members.user_id', 'users.id')
                    ->whereIn('circle_members.circle_id', $circleSubquery)
                    ->whereIn('circle_members.role', $leadershipRoles)
                    ->where('circle_members.status', 'approved')
                    ->whereNull('circle_members.deleted_at');
            })
            ->count();

        $roleBreakdown = [];
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        $roleCol = $isPg ? DB::raw('circle_members.role::text') : 'circle_members.role';

        foreach (['chair', 'vice_chair', 'secretary', 'committee_leader', 'joint_secretary', 'treasurer'] as $rName) {
            $roleQuery = User::query();
            $this->scope->applyUsersScope($roleQuery, $admin);
            $roleBreakdown[$rName] = $roleQuery
                ->whereExists(function ($q) use ($circleSubquery, $rName, $roleCol) {
                    $q->selectRaw(1)
                        ->from('circle_members')
                        ->whereColumn('circle_members.user_id', 'users.id')
                        ->whereIn('circle_members.circle_id', $circleSubquery)
                        ->where($roleCol, $rName)
                        ->where('circle_members.status', 'approved')
                        ->whereNull('circle_members.deleted_at');
                })
                ->count();
        }

        $recentLeadershipTeam = User::query()
            ->with(['city', 'activeCircle:id,name'])
            ->whereExists(function ($q) use ($circleSubquery, $leadershipRoles) {
                $q->selectRaw(1)
                    ->from('circle_members')
                    ->whereColumn('circle_members.user_id', 'users.id')
                    ->whereIn('circle_members.circle_id', $circleSubquery)
                    ->whereIn('circle_members.role', $leadershipRoles)
                    ->where('circle_members.status', 'approved')
                    ->whereNull('circle_members.deleted_at');
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        // 5. Members
        $membersQuery = User::query();
        $this->scope->applyUsersScope($membersQuery, $admin);
        $membersCount = $membersQuery
            ->whereExists(function ($q) use ($circleSubquery) {
                $q->selectRaw(1)
                    ->from('circle_members')
                    ->whereColumn('circle_members.user_id', 'users.id')
                    ->whereIn('circle_members.circle_id', $circleSubquery)
                    ->where('circle_members.role', 'member')
                    ->where('circle_members.status', 'approved')
                    ->whereNull('circle_members.deleted_at');
            })
            ->count();

        return [
            'industry_directors' => [
                'count' => $industryDirectorCount,
                'recent' => $recentIndustryDirectors,
            ],
            'circle_founders' => [
                'count' => $circleFounderCount,
                'recent' => $recentCircleFounders,
            ],
            'circle_directors' => [
                'count' => $circleDirectorCount,
                'recent' => $recentCircleDirectors,
            ],
            'leadership_team' => [
                'count' => $leadershipTeamCount,
                'breakdown' => $roleBreakdown,
                'recent' => $recentLeadershipTeam,
            ],
            'members' => [
                'count' => $membersCount,
            ],
        ];
    }
}
