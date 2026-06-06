<?php

namespace App\Services\Api\Ded;

use App\Models\AdminUser;
use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\CircleSubscription;
use App\Models\User;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardAggregationService
{
    public function __construct(
        private readonly DistrictScopeService $scope,
        private readonly LeadershipSummaryService $leadership,
        private readonly DistrictAnalyticsService $analytics
    ) {}

    /**
     * Compile the full command center dashboard dataset.
     */
    public function getDashboardData(AdminUser $admin, ?string $selectedCircleId = null): array
    {
        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $circleSubquery->where('id', $selectedCircleId);
        }

        $districtCircleIds = (clone $circleSubquery)->pluck('id')->all();

        // 1. Leadership Summary & Role Breakdown
        $leadershipSummary = $this->leadership->getLeadershipSummary($admin, $selectedCircleId);
        
        $totalRolesSum = $leadershipSummary['industry_directors']['count']
            + $leadershipSummary['circle_founders']['count']
            + $leadershipSummary['circle_directors']['count']
            + $leadershipSummary['leadership_team']['count']
            + $leadershipSummary['members']['count'];

        $roleBreakdown = [
            [
                'role' => 'Industry Directors',
                'count' => $leadershipSummary['industry_directors']['count'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['industry_directors']['count'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Circle Founders',
                'count' => $leadershipSummary['circle_founders']['count'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['circle_founders']['count'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Circle Directors',
                'count' => $leadershipSummary['circle_directors']['count'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['circle_directors']['count'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Chairs',
                'count' => $leadershipSummary['leadership_team']['breakdown']['chair'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['leadership_team']['breakdown']['chair'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Vice Chairs',
                'count' => $leadershipSummary['leadership_team']['breakdown']['vice_chair'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['leadership_team']['breakdown']['vice_chair'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Secretaries',
                'count' => $leadershipSummary['leadership_team']['breakdown']['secretary'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['leadership_team']['breakdown']['secretary'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
            [
                'role' => 'Members',
                'count' => $leadershipSummary['members']['count'],
                'percentage' => $totalRolesSum > 0 ? round(($leadershipSummary['members']['count'] / $totalRolesSum) * 100, 1) : 0.0,
            ],
        ];

        // 2. Total pending request counts for Action Center
        $pendingRequests = [
            'visitor_registrations' => $this->safeTableCount('visitor_registrations', 'user_id', $admin),
            'event_joining_requests' => $this->safeTableCount('event_registration_requests', 'user_id', $admin),
            'coin_claims' => $this->safeTableCount('coin_claim_requests', 'user_id', $admin),
            'circle_joining_requests' => $this->analytics->getPendingApprovalsCount($admin, $selectedCircleId),
            'pending_impacts' => $this->safeTableCount('impacts', 'user_id', $admin, 'pending'),
        ];

        // 3. Master Search & Finder lists for Quick Finder
        $quickFinder = [
            'circle_founders' => $leadershipSummary['circle_founders']['recent']->map(fn($u) => $this->userMeta($u))->all(),
            'circle_directors' => $leadershipSummary['circle_directors']['recent']->map(fn($u) => $this->userMeta($u))->all(),
            'industry_directors' => $leadershipSummary['industry_directors']['recent']->map(fn($u) => $this->userMeta($u))->all(),
            'chairs' => User::query()
                ->whereExists(function ($q) use ($districtCircleIds) {
                    $q->selectRaw(1)->from('circle_members')
                        ->whereColumn('circle_members.user_id', 'users.id')
                        ->whereIn('circle_members.circle_id', $districtCircleIds)
                        ->whereIn('circle_members.role', ['chair', 'vice_chair'])
                        ->where('circle_members.status', 'approved')
                        ->whereNull('circle_members.deleted_at');
                })->limit(10)->get(['id', 'display_name', 'email', 'phone', 'company_name', 'first_name', 'last_name'])->map(fn($u) => $this->userMeta($u))->all(),
        ];

        return [
            'master_overview' => [
                'total_members' => [
                    'value' => $this->analytics->getPeersCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getPeersTrend($admin, $selectedCircleId),
                ],
                'total_circles' => [
                    'value' => $this->analytics->getCirclesCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getCirclesTrend($admin, $selectedCircleId),
                ],
                'total_industries' => [
                    'value' => $this->analytics->getIndustriesCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getIndustriesTrend($admin, $selectedCircleId),
                ],
                'total_revenue' => [
                    'value' => $this->analytics->getRevenueCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getRevenueTrend($admin, $selectedCircleId),
                ],
                'total_lives_impacted' => [
                    'value' => $this->analytics->getLivesImpactedCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getLivesImpactedTrend($admin, $selectedCircleId),
                ],
                'upcoming_events' => [
                    'value' => $this->analytics->getUpcomingEventsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getUpcomingEventsTrend($admin, $selectedCircleId),
                ],
                'pending_approvals' => [
                    'value' => $this->analytics->getPendingApprovalsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getPendingApprovalsTrend($admin, $selectedCircleId),
                ],
                'pending_payments' => [
                    'value' => $this->analytics->getPendingPaymentsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getPendingPaymentsTrend($admin, $selectedCircleId),
                ],
                'total_coins' => [
                    'value' => $this->analytics->getCoinsEarnedCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getCoinsEarnedTrend($admin, $selectedCircleId),
                ],
                'total_meetings' => [
                    'value' => $this->analytics->getP2pMeetingsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getP2pMeetingsTrend($admin, $selectedCircleId),
                ],
                'total_deals' => [
                    'value' => $this->analytics->getBusinessDealsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getBusinessDealsTrend($admin, $selectedCircleId),
                ],
                'total_testimonials' => [
                    'value' => $this->analytics->getTestimonialsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getTestimonialsTrend($admin, $selectedCircleId),
                ],
                'total_requirements' => [
                    'value' => $this->analytics->getRequirementsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getRequirementsTrend($admin, $selectedCircleId),
                ],
                'total_referrals' => [
                    'value' => $this->analytics->getReferralsCount($admin, $selectedCircleId),
                    'trend' => $this->analytics->getReferralsTrend($admin, $selectedCircleId),
                ],
            ],
            'leadership_overview' => [
                'industry_directors' => [
                    'count' => $leadershipSummary['industry_directors']['count'],
                    'recent' => $leadershipSummary['industry_directors']['recent']->map(fn($u) => $this->userMeta($u))->all(),
                ],
                'circle_founders' => [
                    'count' => $leadershipSummary['circle_founders']['count'],
                    'recent' => $leadershipSummary['circle_founders']['recent']->map(fn($u) => $this->userMeta($u))->all(),
                ],
                'circle_direct' => [
                    'count' => $leadershipSummary['circle_directors']['count'],
                    'recent' => $leadershipSummary['circle_directors']['recent']->map(fn($u) => $this->userMeta($u))->all(),
                ],
                'leadership_team' => [
                    'count' => $leadershipSummary['leadership_team']['count'],
                    'breakdown' => $leadershipSummary['leadership_team']['breakdown'],
                    'recent' => $leadershipSummary['leadership_team']['recent']->map(fn($u) => $this->userMeta($u))->all(),
                ],
                'members' => [
                    'count' => $leadershipSummary['members']['count'],
                ],
            ],
            'role_breakdown' => $roleBreakdown,
            'circle_overview' => [],
            'pending_requests' => $pendingRequests,
            'health_score' => $this->analytics->getHealthScores($admin, $selectedCircleId),
            'activity_feed' => $this->analytics->getRecentActivityFeed($admin),
            'leadership_quick_finder' => $quickFinder,
        ];
    }

    private function safeTableCount(string $table, string $userColumn, AdminUser $admin, ?string $status = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $userColumn)) {
            return 0;
        }

        $query = DB::table($table);
        $this->scope->applyActivityScope($query, $admin, "{$table}.{$userColumn}");

        if ($status && Schema::hasColumn($table, 'status')) {
            $query->where('status', $status);
        } elseif (Schema::hasColumn($table, 'status') && $table === 'circle_join_requests') {
            $query->whereIn('status', ['pending_cd_approval', 'pending_id_approval', 'pending_circle_fee']);
        }

        return (int) $query->count();
    }

    private function userMeta(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->display_name ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'email' => $u->email,
            'phone' => $u->phone,
            'company' => $u->company_name,
        ];
    }
}
