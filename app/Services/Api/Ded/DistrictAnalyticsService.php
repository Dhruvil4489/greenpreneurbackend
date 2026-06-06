<?php

namespace App\Services\Api\Ded;

use App\Models\AdminUser;
use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\User;
use App\Models\Referral;
use App\Models\P2pMeeting;
use App\Models\BusinessDeal;
use App\Models\Requirement;
use App\Models\Testimonial;
use App\Models\CircleJoinRequest;
use App\Models\Event;
use App\Models\CircleSubscription;
use App\Support\AdminCircleScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class DistrictAnalyticsService
{
    public function __construct(private readonly DistrictScopeService $scope) {}

    /**
     * Get district health scores and KPIs.
     */
    public function getHealthScores(AdminUser $admin, ?string $selectedCircleId = null): array
    {
        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $circleSubquery->where('id', $selectedCircleId);
        }

        $peersQuery = User::query();
        $this->scope->applyUsersScope($peersQuery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($peersQuery, $selectedCircleId);
        }
        $totalPeers = (int) $peersQuery->count();

        $totalCircles = (int) Circle::query()->whereIn('id', $circleSubquery)->count();

        // 1. Active Members % (status is active)
        $activePeersCount = 0;
        if ($totalPeers > 0) {
            $activePeersCount = (clone $peersQuery)
                ->where('status', 'active')
                ->count();
        }
        $activeMembersPct = $totalPeers > 0 ? round(($activePeersCount / $totalPeers) * 100, 1) : 0.0;

        // 2. Leadership Filled % (circles with Chair, Vice Chair, and Secretary assigned)
        $leadershipFilledPct = 0.0;
        if ($totalCircles > 0) {
            $rolesByCircle = DB::table('circle_members')
                ->whereIn('circle_id', $circleSubquery)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->select('circle_id',
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'chair' THEN user_id END) as has_chair"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'vice_chair' THEN user_id END) as has_vc"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'secretary' THEN user_id END) as has_sec")
                )
                ->groupBy('circle_id')
                ->get();

            $filledCount = $rolesByCircle->filter(function ($c) {
                return $c->has_chair >= 1 && $c->has_vc >= 1 && $c->has_sec >= 1;
            })->count();

            $leadershipFilledPct = round(($filledCount / $totalCircles) * 100, 1);
        }

        // 3. Membership Conversion % (Approved / Total requests)
        $joinRequestsQuery = CircleJoinRequest::query();
        $joinRequestsQuery->visibleToAdminUser($admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $joinRequestsQuery->where('circle_id', $selectedCircleId);
        }
        $totalRequests = (int) $joinRequestsQuery->count();
        $approvedRequests = (int) (clone $joinRequestsQuery)->where('status', 'paid')->count();
        $membershipConversionPct = $totalRequests > 0 ? round(($approvedRequests / $totalRequests) * 100, 1) : 0.0;

        // 4. Activity percentages in past 30 days
        $referralPeers = 0;
        $meetingPeers = 0;
        $dealPeers = 0;

        if ($totalPeers > 0) {
            $thirtyDaysAgo = Carbon::now()->subDays(30);

            // Referrals
            if (DB::getSchemaBuilder()->hasTable('referrals')) {
                $referralPeers = DB::table('referrals')
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->where('is_deleted', false)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($peersQuery) {
                        $q->whereIn('from_user_id', (clone $peersQuery)->select('id'))
                          ->orWhereIn('to_user_id', (clone $peersQuery)->select('id'));
                    })
                    ->distinct()
                    ->count('from_user_id');
            }

            // Meetings
            if (DB::getSchemaBuilder()->hasTable('p2p_meetings')) {
                $meetingPeers = DB::table('p2p_meetings')
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->where('is_deleted', false)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($peersQuery) {
                        $q->whereIn('initiator_user_id', (clone $peersQuery)->select('id'))
                          ->orWhereIn('peer_user_id', (clone $peersQuery)->select('id'));
                    })
                    ->distinct()
                    ->count('initiator_user_id');
            }

            // Business Deals
            if (DB::getSchemaBuilder()->hasTable('business_deals')) {
                $dealPeers = DB::table('business_deals')
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->where('is_deleted', false)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($peersQuery) {
                        $q->whereIn('from_user_id', (clone $peersQuery)->select('id'))
                          ->orWhereIn('to_user_id', (clone $peersQuery)->select('id'));
                    })
                    ->distinct()
                    ->count('from_user_id');
            }
        }

        $referralActivityPct = $totalPeers > 0 ? round(($referralPeers / $totalPeers) * 100, 1) : 0.0;
        $meetingActivityPct = $totalPeers > 0 ? round(($meetingPeers / $totalPeers) * 100, 1) : 0.0;
        $businessDealActivityPct = $totalPeers > 0 ? round(($dealPeers / $totalPeers) * 100, 1) : 0.0;

        return [
            'active_members_pct' => $activeMembersPct,
            'leadership_filled_pct' => $leadershipFilledPct,
            'membership_conversion_pct' => $membershipConversionPct,
            'referral_activity_pct' => $referralActivityPct,
            'meeting_activity_pct' => $meetingActivityPct,
            'business_deal_activity_pct' => $businessDealActivityPct,
        ];
    }

    /**
     * Get recent unified activity feed.
     */
    public function getRecentActivityFeed(AdminUser $admin, int $limit = 15): array
    {
        $feed = [];

        // 1. Referrals
        if (DB::getSchemaBuilder()->hasTable('referrals')) {
            $referralsQuery = Referral::query()->with(['fromUser:id,first_name,last_name,display_name', 'toUser:id,first_name,last_name,display_name']);
            $this->scope->applyActivityScope($referralsQuery, $admin, 'referrals.from_user_id', 'referrals.to_user_id');
            $referrals = $referralsQuery->latest('created_at')->limit(5)->get();
            foreach ($referrals as $r) {
                $fromName = optional($r->fromUser)->display_name ?: 'A member';
                $toName = optional($r->toUser)->display_name ?: 'another member';
                $feed[] = [
                    'type' => 'referral',
                    'title' => 'Business Referral Passed',
                    'description' => "{$fromName} referred a business opportunity to {$toName}.",
                    'timestamp' => optional($r->created_at)->toISOString(),
                    'raw_time' => $r->created_at,
                ];
            }
        }

        // 2. P2P Meetings
        if (DB::getSchemaBuilder()->hasTable('p2p_meetings')) {
            $meetingsQuery = P2pMeeting::query()->with(['initiator:id,first_name,last_name,display_name', 'peer:id,first_name,last_name,display_name']);
            $this->scope->applyActivityScope($meetingsQuery, $admin, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id');
            $meetings = $meetingsQuery->latest('created_at')->limit(5)->get();
            foreach ($meetings as $m) {
                $initName = optional($m->initiator)->display_name ?: 'A member';
                $peerName = optional($m->peer)->display_name ?: 'another member';
                $feed[] = [
                    'type' => 'meeting',
                    'title' => 'P2P Meeting Scheduled',
                    'description' => "{$initName} met with {$peerName} for a P2P business meeting.",
                    'timestamp' => optional($m->created_at)->toISOString(),
                    'raw_time' => $m->created_at,
                ];
            }
        }

        // 3. Business Deals
        if (DB::getSchemaBuilder()->hasTable('business_deals')) {
            $dealsQuery = BusinessDeal::query()->with(['fromUser:id,first_name,last_name,display_name', 'toUser:id,first_name,last_name,display_name']);
            $this->scope->applyActivityScope($dealsQuery, $admin, 'business_deals.from_user_id', 'business_deals.to_user_id');
            $deals = $dealsQuery->latest('created_at')->limit(5)->get();
            foreach ($deals as $d) {
                $fromName = optional($d->fromUser)->display_name ?: 'A member';
                $toName = optional($d->toUser)->display_name ?: 'another member';
                $feed[] = [
                    'type' => 'deal',
                    'title' => 'Business Deal Closed',
                    'description' => "{$fromName} closed a deal with {$toName} valued at INR " . number_format($d->deal_amount ?? 0) . ".",
                    'timestamp' => optional($d->created_at)->toISOString(),
                    'raw_time' => $d->created_at,
                ];
            }
        }

        // 4. Circle Joins
        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);

        $joins = CircleMember::query()
            ->with(['user:id,first_name,last_name,display_name', 'circle:id,name'])
            ->whereIn('circle_id', $circleSubquery)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($joins as $j) {
            $userName = optional($j->user)->display_name ?: 'A peer';
            $circleName = optional($j->circle)->name ?: 'a circle';
            $feed[] = [
                'type' => 'circle_join',
                'title' => 'Member Joined Circle',
                'description' => "{$userName} joined the {$circleName} circle.",
                'timestamp' => optional($j->created_at)->toISOString(),
                'raw_time' => $j->created_at,
            ];
        }

        // Sort by timestamp desc and limit
        usort($feed, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return array_slice($feed, 0, $limit);
    }

    public function getPeersCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        $query = User::query();
        AdminCircleScope::applyToUsersQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($query, $selectedCircleId);
        }
        return (int) $query->count();
    }

    public function getCirclesCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        return (int) $query->count();
    }

    public function getIndustriesCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        $circleIds = $query->pluck('id')->all();
        
        return $this->getIndustryCountForCircles($circleIds);
    }

    public function getRevenueCount(AdminUser $admin, ?string $selectedCircleId = null): float
    {
        if (!Schema::hasTable('circle_subscriptions')) {
            return 0.0;
        }
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        $circleIds = $query->pluck('id')->all();

        return (float) CircleSubscription::query()
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->sum('amount');
    }

    public function getLivesImpactedCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        $query = User::query();
        AdminCircleScope::applyToUsersQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($query, $selectedCircleId);
        }
        return (int) $query->sum('users.life_impacted_count');
    }

    public function getUpcomingEventsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('events')) {
            return 0;
        }
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        $circleIds = $query->pluck('id')->all();

        return (int) Event::query()
            ->whereIn('circle_id', $circleIds)
            ->where('start_at', '>=', now())
            ->count();
    }

    public function getPendingApprovalsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('circle_join_requests')) {
            return 0;
        }
        $query = CircleJoinRequest::query();
        $query->visibleToAdminUser($admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('circle_id', $selectedCircleId);
        }

        $query->whereIn('circle_join_requests.status', [
                CircleJoinRequest::STATUS_PENDING_CD_APPROVAL,
                CircleJoinRequest::STATUS_PENDING_ID_APPROVAL,
            ])
            ->where(function ($q) {
                $q->whereNull('circle_join_requests.ded_approval_status')
                  ->orWhere('circle_join_requests.ded_approval_status', '!=', 'approved')
                  ->orWhereNull('circle_join_requests.ded_approved_at');
            });

        return (int) $query->count();
    }

    public function getPendingPaymentsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('circle_join_requests')) {
            return 0;
        }
        $query = CircleJoinRequest::query();
        $query->visibleToAdminUser($admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('circle_id', $selectedCircleId);
        }

        $query->where('circle_join_requests.status', CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE)
            ->whereNull('circle_join_requests.fee_paid_at');

        return (int) $query->count();
    }

    public function getCoinsEarnedCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        $query = User::query();
        AdminCircleScope::applyToUsersQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($query, $selectedCircleId);
        }
        return (int) $query->sum('users.coins_balance');
    }

    public function getP2pMeetingsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('p2p_meetings')) {
            return 0;
        }
        $query = P2pMeeting::query();
        AdminCircleScope::applyToActivityQuery($query, $admin, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id');

        if (Schema::hasColumn('p2p_meetings', 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($query, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id', $selectedCircleId);
        }

        return (int) $query->count();
    }

    public function getBusinessDealsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('business_deals')) {
            return 0;
        }
        $query = BusinessDeal::query();
        AdminCircleScope::applyToActivityQuery($query, $admin, 'business_deals.from_user_id', 'business_deals.to_user_id');

        if (Schema::hasColumn('business_deals', 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($query, 'business_deals.from_user_id', 'business_deals.to_user_id', $selectedCircleId);
        }

        return (int) $query->count();
    }

    public function getTestimonialsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('testimonials')) {
            return 0;
        }
        $query = Testimonial::query();
        AdminCircleScope::applyToActivityQuery($query, $admin, 'testimonials.from_user_id', 'testimonials.to_user_id');

        if (Schema::hasColumn('testimonials', 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($query, 'testimonials.from_user_id', 'testimonials.to_user_id', $selectedCircleId);
        }

        return (int) $query->count();
    }

    public function getRequirementsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('requirements')) {
            return 0;
        }
        $query = Requirement::query();
        AdminCircleScope::applyToActivityQuery($query, $admin, 'requirements.user_id', null);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($query, 'requirements.user_id', null, $selectedCircleId);
        }

        return (int) $query->count();
    }

    public function getReferralsCount(AdminUser $admin, ?string $selectedCircleId = null): int
    {
        if (!Schema::hasTable('referrals')) {
            return 0;
        }
        $query = Referral::query();
        AdminCircleScope::applyToActivityQuery($query, $admin, 'referrals.from_user_id', 'referrals.to_user_id');

        if (Schema::hasColumn('referrals', 'is_deleted')) {
            $query->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($query, 'referrals.from_user_id', 'referrals.to_user_id', $selectedCircleId);
        }

        return (int) $query->count();
    }

    private function applyCircleFilterToUsersQuery($query, string $circleId): void
    {
        $query->whereExists(function ($subQuery) use ($circleId) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm_filter')
                ->whereColumn('cm_filter.user_id', 'users.id')
                ->where('cm_filter.status', 'approved')
                ->whereNull('cm_filter.deleted_at')
                ->where('cm_filter.circle_id', $circleId);
        });
    }

    private function applyCircleFilterToActivityQuery($query, string $userColumn, ?string $peerColumn, string $circleId): void
    {
        $query->whereExists(function ($subQuery) use ($userColumn, $circleId) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm_filter')
                ->whereColumn('cm_filter.user_id', $userColumn)
                ->where('cm_filter.circle_id', $circleId);
        });
    }

    private function getIndustryCountForCircles(array $circleIds): int
    {
        $mappedCategoryIds = DB::table('circle_category_mappings')
            ->whereIn('circle_id', $circleIds)
            ->pluck('category_id')
            ->unique()
            ->toArray();

        $circles = DB::table('circles')->whereIn('id', $circleIds)->get();
        
        $categoryMap = [
            'manufacturing' => 14,
            'engineering' => 14,
            'real estate' => 15,
            'construction' => 15,
            'infrastructure' => 15,
            'buildcon' => 15,
            'technology' => 16,
            'digital' => 16,
            'healthcare' => 17,
            'wellness' => 17,
            'life sciences' => 17,
            'healthopedia' => 17,
            'education' => 18,
            'training' => 18,
            'skill' => 18,
            'events' => 19,
            'fashion' => 19,
            'apparel' => 19,
            'lifestyle' => 19,
            'csr' => 20,
            'ngo' => 20,
            'nation-building' => 20,
            'cross-border' => 21,
            'global expansion' => 21,
            'sustainable' => 22,
            'esg' => 22,
            'import' => 23,
            'export' => 23,
            'global trade' => 23,
            'startup' => 24,
            'sme ipo' => 25,
            'ipo goal' => 25,
            'investors' => 26,
            'franchise' => 27,
            'licensing' => 27,
            'msme' => 28,
            'winners' => 28,
            'family business' => 29,
            'young' => 30,
            'leadership' => 31,
            'transformation' => 31,
        ];

        $detectedCategoryIds = array_flip($mappedCategoryIds);

        foreach ($circles as $circle) {
            $nameLower = mb_strtolower($circle->name);
            $matched = false;
            foreach ($categoryMap as $keyword => $catId) {
                if (str_contains($nameLower, $keyword)) {
                    $detectedCategoryIds[$catId] = true;
                    $matched = true;
                }
            }
            if (!$matched && !empty($circle->industry_tags)) {
                $tags = is_string($circle->industry_tags) ? json_decode($circle->industry_tags, true) : $circle->industry_tags;
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $tagLower = mb_strtolower($tag);
                        foreach ($categoryMap as $keyword => $catId) {
                            if (str_contains($tagLower, $keyword)) {
                                $detectedCategoryIds[$catId] = true;
                            }
                        }
                    }
                }
            }
        }

        return count($detectedCategoryIds);
    }

    private function calculatePercentageTrend(int $current30d, int $previous30d): string
    {
        if ($previous30d === 0) {
            return $current30d > 0 ? "+100% vs previous 30 days" : "0% vs previous 30 days";
        }
        $diffPct = round((($current30d - $previous30d) / $previous30d) * 100);
        $sign = $diffPct >= 0 ? "+" : "";
        return "{$sign}{$diffPct}% vs previous 30 days";
    }

    private function formatAbsoluteTrend(float|int $current, float|int $previous, string $label = 'vs previous 30 days', bool $isCurrency = false): string
    {
        $diff = $current - $previous;
        if ($diff > 0) {
            $formattedVal = $isCurrency ? '₹' . number_format(round($diff)) : number_format(abs($diff));
            return "↑ {$formattedVal} {$label}";
        } elseif ($diff < 0) {
            $formattedVal = $isCurrency ? '₹' . number_format(round(abs($diff))) : number_format(abs($diff));
            return "↓ {$formattedVal} {$label}";
        }
        return "No Change";
    }

    public function getPeersTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        $queryCurrent = User::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = User::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToUsersQuery($queryCurrent, $admin);
        AdminCircleScope::applyToUsersQuery($queryPrevious, $admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($queryCurrent, $selectedCircleId);
            $this->applyCircleFilterToUsersQuery($queryPrevious, $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getCirclesTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        $queryCurrent = Circle::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = Circle::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToCirclesQuery($queryCurrent, $admin);
        AdminCircleScope::applyToCirclesQuery($queryPrevious, $admin);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $queryCurrent->where('id', $selectedCircleId);
            $queryPrevious->where('id', $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getIndustriesTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        $queryCurrent = Circle::query();
        AdminCircleScope::applyToCirclesQuery($queryCurrent, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $queryCurrent->where('id', $selectedCircleId);
        }
        $currentCircleIds = $queryCurrent->pluck('id')->all();
        $currentCount = $this->getIndustryCountForCircles($currentCircleIds);

        $queryPrevious = Circle::query()->where('created_at', '<', now()->subDays(30));
        AdminCircleScope::applyToCirclesQuery($queryPrevious, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $queryPrevious->where('id', $selectedCircleId);
        }
        $previousCircleIds = $queryPrevious->pluck('id')->all();
        $previousCount = $this->getIndustryCountForCircles($previousCircleIds);

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getRevenueTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('circle_subscriptions')) {
            return "";
        }
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        $circleIds = $query->pluck('id')->all();

        $current30d = (float) CircleSubscription::query()
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        $previous30d = (float) CircleSubscription::query()
            ->whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->sum('amount');

        return $this->formatAbsoluteTrend($current30d, $previous30d, 'vs previous 30 days', true);
    }

    public function getLivesImpactedTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('life_impact_histories')) {
            return "";
        }
        $peersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($peersQuery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($peersQuery, $selectedCircleId);
        }
        $userIds = $peersQuery->pluck('id')->all();

        $current30d = (int) DB::table('life_impact_histories')
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('impact_value');

        $previous30d = (int) DB::table('life_impact_histories')
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->sum('impact_value');

        return $this->formatAbsoluteTrend($current30d, $previous30d);
    }

    public function getUpcomingEventsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('events')) {
            return "";
        }
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('id', $selectedCircleId);
        }
        $circleIds = $query->pluck('id')->all();

        $current30d = (int) Event::query()
            ->whereIn('circle_id', $circleIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $previous30d = (int) Event::query()
            ->whereIn('circle_id', $circleIds)
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->count();

        return $this->formatAbsoluteTrend($current30d, $previous30d);
    }

    public function getPendingApprovalsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('circle_join_requests')) {
            return "";
        }
        $query = CircleJoinRequest::query()->visibleToAdminUser($admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('circle_id', $selectedCircleId);
        }
        $query->whereIn('circle_join_requests.status', [
            CircleJoinRequest::STATUS_PENDING_CD_APPROVAL,
            CircleJoinRequest::STATUS_PENDING_ID_APPROVAL,
        ])
        ->where(function ($q) {
            $q->whereNull('circle_join_requests.ded_approval_status')
              ->orWhere('circle_join_requests.ded_approval_status', '!=', 'approved')
              ->orWhereNull('circle_join_requests.ded_approved_at');
        });

        $currentWeek = (int) (clone $query)->where('created_at', '>=', now()->subDays(7))->count();
        $previousWeek = (int) (clone $query)->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();

        return $this->formatAbsoluteTrend($currentWeek, $previousWeek, 'vs last week');
    }

    public function getPendingPaymentsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('circle_join_requests')) {
            return "";
        }
        $query = CircleJoinRequest::query()->visibleToAdminUser($admin)
            ->where('circle_join_requests.status', CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE)
            ->whereNull('circle_join_requests.fee_paid_at');

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('circle_id', $selectedCircleId);
        }

        $current30d = (int) (clone $query)->where('created_at', '>=', now()->subDays(30))->count();
        $previous30d = (int) (clone $query)->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])->count();

        return $this->formatAbsoluteTrend($current30d, $previous30d);
    }

    public function getCoinsEarnedTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('coins_ledger')) {
            return "";
        }
        $peersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($peersQuery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($peersQuery, $selectedCircleId);
        }

        $date_30d_ago = now()->subDays(30);
        $date_60d_ago = now()->subDays(60);

        $query = DB::table('users as u')
            ->whereIn('u.id', $peersQuery->select('id'))
            ->leftJoin(DB::raw("(
                SELECT user_id, SUM(amount) as sum_amount
                FROM coins_ledger
                WHERE created_at >= " . DB::getPdo()->quote($date_30d_ago) . "
                GROUP BY user_id
            ) as l30"), 'l30.user_id', '=', 'u.id')
            ->leftJoin(DB::raw("(
                SELECT user_id, SUM(amount) as sum_amount
                FROM coins_ledger
                WHERE created_at >= " . DB::getPdo()->quote($date_60d_ago) . "
                GROUP BY user_id
            ) as l60"), 'l60.user_id', '=', 'u.id')
            ->selectRaw("
                SUM(u.coins_balance) as balance_now,
                SUM(CASE 
                    WHEN u.created_at >= ? THEN 0
                    ELSE 
                        CASE 
                            WHEN (u.coins_balance - COALESCE(l30.sum_amount, 0)) < 0 THEN 0 
                            ELSE (u.coins_balance - COALESCE(l30.sum_amount, 0)) 
                        END
                END) as balance_30d,
                SUM(CASE 
                    WHEN u.created_at >= ? THEN 0
                    ELSE 
                        CASE 
                            WHEN (u.coins_balance - COALESCE(l60.sum_amount, 0)) < 0 THEN 0 
                            ELSE (u.coins_balance - COALESCE(l60.sum_amount, 0)) 
                        END
                END) as balance_60d
            ", [$date_30d_ago, $date_60d_ago])
            ->first();

        $balance_now = (int) ($query->balance_now ?? 0);
        $balance_30d_ago = (int) ($query->balance_30d ?? 0);
        $balance_60d_ago = (int) ($query->balance_60d ?? 0);

        $current30d = $balance_now - $balance_30d_ago;
        $previous30d = $balance_30d_ago - $balance_60d_ago;

        return $this->formatAbsoluteTrend($current30d, $previous30d);
    }

    public function getP2pMeetingsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('p2p_meetings')) {
            return "";
        }
        $queryCurrent = P2pMeeting::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = P2pMeeting::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToActivityQuery($queryCurrent, $admin, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id');
        AdminCircleScope::applyToActivityQuery($queryPrevious, $admin, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id');

        if (Schema::hasColumn('p2p_meetings', 'is_deleted')) {
            $queryCurrent->where('is_deleted', false);
            $queryPrevious->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($queryCurrent, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id', $selectedCircleId);
            $this->applyCircleFilterToActivityQuery($queryPrevious, 'p2p_meetings.initiator_user_id', 'p2p_meetings.peer_user_id', $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getBusinessDealsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('business_deals')) {
            return "";
        }
        $queryCurrent = BusinessDeal::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = BusinessDeal::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToActivityQuery($queryCurrent, $admin, 'business_deals.from_user_id', 'business_deals.to_user_id');
        AdminCircleScope::applyToActivityQuery($queryPrevious, $admin, 'business_deals.from_user_id', 'business_deals.to_user_id');

        if (Schema::hasColumn('business_deals', 'is_deleted')) {
            $queryCurrent->where('is_deleted', false);
            $queryPrevious->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($queryCurrent, 'business_deals.from_user_id', 'business_deals.to_user_id', $selectedCircleId);
            $this->applyCircleFilterToActivityQuery($queryPrevious, 'business_deals.from_user_id', 'business_deals.to_user_id', $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getTestimonialsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('testimonials')) {
            return "";
        }
        $queryCurrent = Testimonial::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = Testimonial::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToActivityQuery($queryCurrent, $admin, 'testimonials.from_user_id', 'testimonials.to_user_id');
        AdminCircleScope::applyToActivityQuery($queryPrevious, $admin, 'testimonials.from_user_id', 'testimonials.to_user_id');

        if (Schema::hasColumn('testimonials', 'is_deleted')) {
            $queryCurrent->where('is_deleted', false);
            $queryPrevious->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($queryCurrent, 'testimonials.from_user_id', 'testimonials.to_user_id', $selectedCircleId);
            $this->applyCircleFilterToActivityQuery($queryPrevious, 'testimonials.from_user_id', 'testimonials.to_user_id', $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getRequirementsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('requirements')) {
            return "";
        }
        $queryCurrent = Requirement::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = Requirement::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToActivityQuery($queryCurrent, $admin, 'requirements.user_id', null);
        AdminCircleScope::applyToActivityQuery($queryPrevious, $admin, 'requirements.user_id', null);

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($queryCurrent, 'requirements.user_id', null, $selectedCircleId);
            $this->applyCircleFilterToActivityQuery($queryPrevious, 'requirements.user_id', null, $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getReferralsTrend(AdminUser $admin, ?string $selectedCircleId = null): string
    {
        if (!Schema::hasTable('referrals')) {
            return "";
        }
        $queryCurrent = Referral::query()->where('created_at', '>=', now()->subDays(30));
        $queryPrevious = Referral::query()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)]);

        AdminCircleScope::applyToActivityQuery($queryCurrent, $admin, 'referrals.from_user_id', 'referrals.to_user_id');
        AdminCircleScope::applyToActivityQuery($queryPrevious, $admin, 'referrals.from_user_id', 'referrals.to_user_id');

        if (Schema::hasColumn('referrals', 'is_deleted')) {
            $queryCurrent->where('is_deleted', false);
            $queryPrevious->where('is_deleted', false);
        }

        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToActivityQuery($queryCurrent, 'referrals.from_user_id', 'referrals.to_user_id', $selectedCircleId);
            $this->applyCircleFilterToActivityQuery($queryPrevious, 'referrals.from_user_id', 'referrals.to_user_id', $selectedCircleId);
        }

        $currentCount = $queryCurrent->count();
        $previousCount = $queryPrevious->count();

        return $this->formatAbsoluteTrend($currentCount, $previousCount);
    }

    public function getUserActivityCounts(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $meetings = DB::table('p2p_meetings')
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function($q) use ($userIds) {
                $q->whereIn('initiator_user_id', $userIds)
                  ->orWhereIn('peer_user_id', $userIds);
            })
            ->get();

        $deals = DB::table('business_deals')
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function($q) use ($userIds) {
                $q->whereIn('from_user_id', $userIds)
                  ->orWhereIn('to_user_id', $userIds);
            })
            ->get();

        $referrals = DB::table('referrals')
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function($q) use ($userIds) {
                $q->whereIn('from_user_id', $userIds)
                  ->orWhereIn('to_user_id', $userIds);
            })
            ->get();

        $requirements = DB::table('requirements')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');

        $testimonials = DB::table('testimonials')
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function($q) use ($userIds) {
                $q->whereIn('from_user_id', $userIds)
                  ->orWhereIn('to_user_id', $userIds);
            })
            ->get();

        $results = [];
        foreach ($userIds as $uid) {
            $userMeetings = $meetings->filter(fn($m) => $m->initiator_user_id === $uid || $m->peer_user_id === $uid)->count();
            $userDeals = $deals->filter(fn($d) => $d->from_user_id === $uid || $d->to_user_id === $uid)->count();
            $userReferrals = $referrals->filter(fn($r) => $r->from_user_id === $uid || $r->to_user_id === $uid)->count();
            $userReqs = isset($requirements[$uid]) ? $requirements[$uid]->count() : 0;
            $userTestimonials = $testimonials->filter(fn($t) => $t->from_user_id === $uid || $t->to_user_id === $uid)->count();

            $results[$uid] = [
                'meetings' => $userMeetings,
                'deals' => $userDeals,
                'referrals' => $userReferrals,
                'requirements' => $userReqs,
                'testimonials' => $userTestimonials,
                'score' => $userMeetings + $userDeals + $userReferrals + $userReqs + $userTestimonials,
            ];
        }

        return $results;
    }

    public function getLeadershipRoleDetails(AdminUser $admin, string $role, array $filters = []): array
    {
        $circleId = $filters['circle_id'] ?? null;
        $industryId = $filters['industry_id'] ?? null;
        $status = $filters['status'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);

        if ($circleId && $circleId !== 'all') {
            $circleSubquery->where('id', $circleId);
        }

        if ($industryId && $industryId !== 'all') {
            $circleSubquery->whereExists(function ($q) use ($industryId) {
                $q->selectRaw(1)->from('circle_category_mappings')
                    ->whereColumn('circle_category_mappings.circle_id', 'circles.id')
                    ->where('circle_category_mappings.category_id', $industryId);
            });
        }

        $districtCircleIds = (clone $circleSubquery)->pluck('id')->all();

        $circles = Circle::query()
            ->whereIn('id', $districtCircleIds)
            ->withCount(['members' => function ($q) {
                $q->where('status', 'approved')->whereNull('deleted_at');
            }])
            ->withSum(['circleSubscriptions as active_revenue' => function ($q) {
                $q->where('status', 'active');
            }], 'amount')
            ->get();

        $records = [];
        $totalCount = 0;
        $revenueContribution = 0.0;
        $totalMembersManaged = 0;
        $totalCirclesCovered = 0;

        if (in_array($role, ['industry_director', 'founder', 'director'])) {
            $userQuery = User::query()->with(['mainBusinessCategory', 'circleMembers.circle']);
            $this->scope->applyUsersScope($userQuery, $admin);

            if ($status && $status !== 'all') {
                $userQuery->where('status', $status);
            }

            if ($dateFrom) {
                $userQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $userQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            if ($role === 'industry_director') {
                $userQuery->whereExists(function ($q) use ($districtCircleIds) {
                    $q->selectRaw(1)->from('circles')
                        ->whereColumn('circles.industry_director_user_id', 'users.id')
                        ->whereIn('circles.id', $districtCircleIds);
                });
            } elseif ($role === 'founder') {
                $userQuery->whereExists(function ($q) use ($districtCircleIds) {
                    $q->selectRaw(1)->from('circles')
                        ->whereColumn('circles.founder_user_id', 'users.id')
                        ->whereIn('circles.id', $districtCircleIds);
                });
            } elseif ($role === 'director') {
                $userQuery->whereExists(function ($q) use ($districtCircleIds) {
                    $q->selectRaw(1)->from('circles')
                        ->whereColumn('circles.director_user_id', 'users.id')
                        ->whereIn('circles.id', $districtCircleIds);
                });
            }

            $users = $userQuery->orderBy('display_name')->get();
            $userIds = $users->pluck('id')->all();
            $activityMap = $this->getUserActivityCounts($userIds);

            $subscriptions = CircleSubscription::query()
                ->whereIn('circle_id', $districtCircleIds)
                ->where('status', 'active')
                ->get()
                ->groupBy(fn($s) => $s->user_id . '_' . $s->circle_id);

            foreach ($users as $u) {
                if ($role === 'industry_director') {
                    $userCircles = $circles->where('industry_director_user_id', $u->id);
                } elseif ($role === 'founder') {
                    $userCircles = $circles->where('founder_user_id', $u->id);
                } else {
                    $userCircles = $circles->where('director_user_id', $u->id);
                }

                if ($userCircles->isEmpty()) {
                    continue;
                }

                $membersManaged = $userCircles->sum('members_count');
                $revenue = (float) $userCircles->sum('active_revenue');
                $acts = $activityMap[$u->id] ?? ['meetings' => 0, 'deals' => 0, 'referrals' => 0, 'requirements' => 0, 'testimonials' => 0, 'score' => 0];

                $circleMembershipsList = $u->circleMembers
                    ->filter(fn($cm) => $cm->status === 'approved' && !$cm->deleted_at && $cm->circle)
                    ->map(fn($cm) => $cm->circle->name)
                    ->unique()
                    ->implode(', ') ?: 'No Circles';

                $rolesList = $u->circleMembers
                    ->filter(fn($cm) => $cm->status === 'approved' && !$cm->deleted_at)
                    ->map(fn($cm) => ucfirst($cm->role))
                    ->unique()
                    ->implode(', ');

                $extraRoles = [];
                if ($circles->where('founder_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Circle Founder';
                }
                if ($circles->where('director_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Circle Director';
                }
                if ($circles->where('industry_director_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Industry Director';
                }
                if (!empty($extraRoles)) {
                    $rolesList = collect(explode(', ', $rolesList))
                        ->merge($extraRoles)
                        ->filter()
                        ->unique()
                        ->implode(', ');
                }
                if (empty($rolesList)) {
                    $rolesList = 'Member';
                }

                $records[] = [
                    'id' => $u->id,
                    'name' => $u->display_name ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'company' => $u->company_name ?: '—',
                    'phone' => $u->phone ?: '—',
                    'email' => $u->email ?: '—',
                    'industry' => $u->mainBusinessCategory?->name ?: '—',
                    'circles' => $userCircles->pluck('name')->implode(', '),
                    'members_count' => $membersManaged,
                    'revenue' => $revenue,
                    'created_at' => $u->created_at,
                    'status' => $u->status ?: 'active',
                    'activity' => $acts,
                    'circle_memberships_list' => $circleMembershipsList,
                    'leadership_roles_list' => $rolesList,
                    'coins_balance' => (int) ($u->coins_balance ?? 0),
                ];

                $revenueContribution += $revenue;
                $totalMembersManaged += $membersManaged;
                $totalCirclesCovered += $userCircles->count();
            }
            $totalCount = count($records);
        } else {
            $membershipQuery = CircleMember::query()
                ->whereIn('circle_id', $districtCircleIds)
                ->where('status', 'approved')
                ->whereNull('deleted_at');

            if ($role === 'chair') {
                $membershipQuery->where('role', 'chair');
            } elseif ($role === 'vice_chair') {
                $membershipQuery->where('role', 'vice_chair');
            } elseif ($role === 'secretary') {
                $membershipQuery->where('role', 'secretary');
            } else {
                $membershipQuery->where('role', 'member');
            }

            if ($status && $status !== 'all') {
                $membershipQuery->whereHas('user', function($q) use ($status) {
                    $q->where('status', $status);
                });
            }

            if ($dateFrom) {
                $membershipQuery->where('joined_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $membershipQuery->where('joined_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            $memberships = $membershipQuery
                ->with(['user.mainBusinessCategory', 'user.circleMembers.circle', 'circle'])
                ->get();

            $userIds = $memberships->pluck('user_id')->unique()->all();
            $activityMap = $this->getUserActivityCounts($userIds);

            $subscriptions = CircleSubscription::query()
                ->whereIn('circle_id', $districtCircleIds)
                ->where('status', 'active')
                ->get()
                ->groupBy(fn($s) => $s->user_id . '_' . $s->circle_id);

            $coveredCircleIds = [];

            foreach ($memberships as $m) {
                $u = $m->user;
                if (!$u) continue;

                $c = $m->circle;
                $circleName = $c ? $c->name : '—';
                $circleMembers = $c ? $circles->firstWhere('id', $c->id)?->members_count ?? 0 : 0;
                $circleRev = $c ? (float) $circles->firstWhere('id', $c->id)?->active_revenue ?? 0.0 : 0.0;

                $subKey = $m->user_id . '_' . $m->circle_id;
                $rev = isset($subscriptions[$subKey]) ? (float) $subscriptions[$subKey]->sum('amount') : 0.0;
                $acts = $activityMap[$m->user_id] ?? ['meetings' => 0, 'deals' => 0, 'referrals' => 0, 'requirements' => 0, 'testimonials' => 0, 'score' => 0];

                $circleMembershipsList = $u->circleMembers
                    ->filter(fn($cm) => $cm->status === 'approved' && !$cm->deleted_at && $cm->circle)
                    ->map(fn($cm) => $cm->circle->name)
                    ->unique()
                    ->implode(', ') ?: 'No Circles';

                $rolesList = $u->circleMembers
                    ->filter(fn($cm) => $cm->status === 'approved' && !$cm->deleted_at)
                    ->map(fn($cm) => ucfirst($cm->role))
                    ->unique()
                    ->implode(', ');

                $extraRoles = [];
                if ($circles->where('founder_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Circle Founder';
                }
                if ($circles->where('director_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Circle Director';
                }
                if ($circles->where('industry_director_user_id', $u->id)->isNotEmpty()) {
                    $extraRoles[] = 'Industry Director';
                }
                if (!empty($extraRoles)) {
                    $rolesList = collect(explode(', ', $rolesList))
                        ->merge($extraRoles)
                        ->filter()
                        ->unique()
                        ->implode(', ');
                }
                if (empty($rolesList)) {
                    $rolesList = 'Member';
                }

                $records[] = [
                    'id' => $u->id,
                    'name' => $u->display_name ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'company' => $u->company_name ?: '—',
                    'phone' => $u->phone ?: '—',
                    'email' => $u->email ?: '—',
                    'industry' => $u->mainBusinessCategory?->name ?: '—',
                    'circle_name' => $circleName,
                    'members_count' => $circleMembers,
                    'revenue' => $role === 'member' ? $rev : $circleRev,
                    'created_at' => $m->joined_at ?: $m->created_at,
                    'status' => $u->status ?: 'active',
                    'activity' => $acts,
                    'circle_memberships_list' => $circleMembershipsList,
                    'leadership_roles_list' => $rolesList,
                    'coins_balance' => (int) ($u->coins_balance ?? 0),
                ];

                $revenueContribution += $role === 'member' ? $rev : $circleRev;
                $totalMembersManaged += $circleMembers;
                if ($c) {
                    $coveredCircleIds[$c->id] = true;
                }
            }

            $totalCount = count($records);
            $totalCirclesCovered = count($coveredCircleIds);
        }

        $allCirclesCount = (int) Circle::query()->whereIn('id', $circleSubquery)->count();
        $coveragePct = $allCirclesCount > 0 ? round(($totalCirclesCovered / $allCirclesCount) * 100, 1) : 0.0;

        return [
            'records' => $records,
            'summary' => [
                'total_count' => $totalCount,
                'revenue_contribution' => $revenueContribution,
                'total_members_managed' => $totalMembersManaged,
                'total_circles_covered' => $totalCirclesCovered,
                'district_coverage_pct' => $coveragePct,
            ]
        ];
    }

    public function getActiveMembersDetail(AdminUser $admin, array $filters = []): array
    {
        $query = User::query();
        AdminCircleScope::applyToUsersQuery($query, $admin);

        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $this->applyCircleFilterToUsersQuery($query, $filters['circle_id']);
        }

        if (!empty($filters['industry_id']) && $filters['industry_id'] !== 'all') {
            $industryId = $filters['industry_id'];
            $query->where(function ($q) use ($industryId) {
                $q->where('users.main_business_category_id', $industryId)
                  ->orWhereExists(function ($sub) use ($industryId) {
                      $sub->selectRaw(1)
                          ->from('circle_categories')
                          ->whereColumn('circle_categories.id', 'users.main_business_category_id')
                          ->where('circle_categories.parent_id', $industryId);
                  });
            });
        }

        // Base query count for total members (denominator) - filtered by creation range if provided
        $totalQuery = clone $query;
        if (!empty($filters['date_from'])) {
            $totalQuery->where('users.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $totalQuery->where('users.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        $totalPeers = (int) $totalQuery->count();


        // Count based on user status
        $activePeersCount = (int) (clone $totalQuery)->where('users.status', 'active')->count();
        $inactivePeersCount = (int) (clone $totalQuery)->where('users.status', 'inactive')->count();
        $percentage = $totalPeers > 0 ? round(($activePeersCount / $totalPeers) * 100, 1) : 0.0;

        // Apply status filter to records query if provided
        $recordsQuery = clone $totalQuery;
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $recordsQuery->where('users.status', $filters['status']);
        }

        // Fetch records with mainBusinessCategory and circle memberships eager loaded
        $records = $recordsQuery->with([
            'mainBusinessCategory:id,name',
            'circleMembers' => function ($q) {
                $q->where('status', 'approved')->whereNull('deleted_at');
            },
            'circleMembers.circle:id,name'
        ])
            ->orderBy('display_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'company_name', 'last_login_at', 'main_business_category_id', 'created_at', 'status']);

        // Format records
        $userIds = $records->pluck('id')->all();
        $activityMap = $this->getUserActivityCounts($userIds);

        $formattedRecords = [];
        foreach ($records as $r) {
            $circleNames = $r->circleMembers->map(fn($cm) => $cm->circle?->name)->filter()->unique()->implode(', ') ?: '—';
            $activityScore = $activityMap[$r->id]['score'] ?? 0;
            $formattedRecords[] = [
                'id' => $r->id,
                'name' => $r->display_name ?: trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                'member_name' => $r->display_name ?: trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                'email' => $r->email ?: '—',
                'phone' => $r->phone ?: '—',
                'company' => $r->company_name ?: '—',
                'industry' => $r->mainBusinessCategory?->name ?: '—',
                'circle' => $circleNames,
                'join_date' => $r->created_at ? $r->created_at->toISOString() : null,
                'last_activity' => $r->last_login_at ? Carbon::parse($r->last_login_at)->toISOString() : null,
                'activity_score' => $activityScore,
                'status' => $r->status ?: 'active',
                'is_active' => strtolower($r->status ?? '') === 'active',
                'created_at' => $r->created_at,
                'last_login_at' => $r->last_login_at,
            ];
        }

        return [
            'records' => $formattedRecords,
            'summary' => [
                'active_count' => $activePeersCount,
                'inactive_count' => $inactivePeersCount,
                'numerator' => $activePeersCount,
                'denominator' => $totalPeers,
                'percentage' => $percentage,
                'formula' => 'Active Percentage = (Active Members / Total Members) * 100',
            ]
        ];
    }

    public function getLeadershipSpotsFilledDetail(AdminUser $admin, array $filters = []): array
    {
        $circleQuery = Circle::query();
        AdminCircleScope::applyToCirclesQuery($circleQuery, $admin);

        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $circleQuery->where('circles.id', $filters['circle_id']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $circleQuery->where('circles.status', $filters['status']);
        }

        if (!empty($filters['industry_id']) && $filters['industry_id'] !== 'all') {
            $industryId = $filters['industry_id'];
            $circleQuery->whereExists(function ($q) use ($industryId) {
                $q->selectRaw(1)->from('circle_category_mappings')
                    ->whereColumn('circle_category_mappings.circle_id', 'circles.id')
                    ->where('circle_category_mappings.category_id', $industryId);
            });
        }

        if (!empty($filters['date_from'])) {
            $circleQuery->where('circles.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $circleQuery->where('circles.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $circlesList = $circleQuery->with([
            'founder:id,display_name,first_name,last_name',
            'director:id,display_name,first_name,last_name',
            'industryDirector:id,display_name,first_name,last_name'
        ])->orderBy('name')->get(['id', 'name', 'status', 'created_at', 'founder_user_id', 'director_user_id', 'industry_director_user_id']);
        $circleIds = $circlesList->pluck('id')->all();
        $totalCircles = count($circleIds);

        $membersQuery = DB::table('circle_members')
            ->join('users', 'users.id', '=', 'circle_members.user_id')
            ->whereIn('circle_members.circle_id', $circleIds ?: [''])
            ->where('circle_members.status', 'approved')
            ->whereNull('circle_members.deleted_at')
            ->select(
                'circle_members.circle_id',
                'circle_members.role',
                'users.id as user_id',
                'users.display_name',
                'users.first_name',
                'users.last_name'
            );

        if (!empty($filters['date_from'])) {
            $membersQuery->where('circle_members.joined_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $membersQuery->where('circle_members.joined_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $members = $membersQuery->get()->groupBy('circle_id');

        $records = [];
        $filledCirclesCount = 0;

        foreach ($circlesList as $c) {
            $cm = $members->get($c->id) ?? collect();
            $chair = $cm->firstWhere('role', 'chair');
            $vc = $cm->firstWhere('role', 'vice_chair');
            $sec = $cm->firstWhere('role', 'secretary');

            $hasChair = !empty($chair);
            $hasVc = !empty($vc);
            $hasSec = !empty($sec);

            $isFilled = $hasChair && $hasVc && $hasSec;
            if ($isFilled) {
                $filledCirclesCount++;
            }

            $founderName = $c->founder ? ($c->founder->display_name ?: trim(($c->founder->first_name ?? '') . ' ' . ($c->founder->last_name ?? ''))) : null;
            $directorName = $c->director ? ($c->director->display_name ?: trim(($c->director->first_name ?? '') . ' ' . ($c->director->last_name ?? ''))) : null;
            $indDirName = $c->industryDirector ? ($c->industryDirector->display_name ?: trim(($c->industryDirector->first_name ?? '') . ' ' . ($c->industryDirector->last_name ?? ''))) : null;

            $records[] = [
                'circle_id' => $c->id,
                'circle_name' => $c->name,
                'circle_status' => $c->status,
                'created_at' => $c->created_at,
                'chair' => $chair ? ($chair->display_name ?: trim(($chair->first_name ?? '') . ' ' . ($chair->last_name ?? ''))) : null,
                'vice_chair' => $vc ? ($vc->display_name ?: trim(($vc->first_name ?? '') . ' ' . ($vc->last_name ?? ''))) : null,
                'secretary' => $sec ? ($sec->display_name ?: trim(($sec->first_name ?? '') . ' ' . ($sec->last_name ?? ''))) : null,
                'has_chair' => $hasChair,
                'has_vc' => $hasVc,
                'has_sec' => $hasSec,
                'is_filled' => $isFilled,
                'founder' => $founderName,
                'director' => $directorName,
                'industry_director' => $indDirName,
            ];
        }

        $percentage = $totalCircles > 0 ? round(($filledCirclesCount / $totalCircles) * 100, 1) : 0.0;

        return [
            'records' => $records,
            'summary' => [
                'numerator' => $filledCirclesCount,
                'denominator' => $totalCircles,
                'percentage' => $percentage,
                'formula' => 'Leadership Spots Filled = (Circles with Chair, VC & Sec Filled / Total Circles) * 100',
            ]
        ];
    }

    public function getMembershipConversionDetail(AdminUser $admin, array $filters = []): array
    {
        $joinRequestsQuery = CircleJoinRequest::query();
        $joinRequestsQuery->visibleToAdminUser($admin);

        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $joinRequestsQuery->where('circle_join_requests.circle_id', $filters['circle_id']);
        }

        if (!empty($filters['industry_id']) && $filters['industry_id'] !== 'all') {
            $industryId = $filters['industry_id'];
            $joinRequestsQuery->whereExists(function ($q) use ($industryId) {
                $q->selectRaw(1)->from('circle_category_mappings')
                    ->whereColumn('circle_category_mappings.circle_id', 'circle_join_requests.circle_id')
                    ->where('circle_category_mappings.category_id', $industryId);
            });
        }

        if (!empty($filters['date_from'])) {
            $joinRequestsQuery->where('circle_join_requests.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $joinRequestsQuery->where('circle_join_requests.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $totalRequests = (int) $joinRequestsQuery->count();
        $approvedRequests = (int) (clone $joinRequestsQuery)->where('circle_join_requests.status', 'paid')->count();
        $percentage = $totalRequests > 0 ? round(($approvedRequests / $totalRequests) * 100, 1) : 0.0;

        $requestsList = $joinRequestsQuery->with([
            'user:id,first_name,last_name,display_name,email,phone,company_name',
            'circle:id,name'
        ])
        ->orderBy('created_at', 'desc')
        ->get();

        $records = [];
        foreach ($requestsList as $r) {
            $records[] = [
                'id' => $r->id,
                'user_name' => $r->user ? ($r->user->display_name ?: trim(($r->user->first_name ?? '') . ' ' . ($r->user->last_name ?? ''))) : '—',
                'user_email' => $r->user?->email ?: '—',
                'user_phone' => $r->user?->phone ?: '—',
                'circle_name' => $r->circle?->name ?: '—',
                'status' => $r->status,
                'is_approved' => $r->status === 'paid',
                'created_at' => $r->created_at,
            ];
        }

        $rejectedRequests = (int) (clone $joinRequestsQuery)
            ->whereIn('circle_join_requests.status', [
                CircleJoinRequest::STATUS_REJECTED_BY_CD,
                CircleJoinRequest::STATUS_REJECTED_BY_ID
            ])->count();

        return [
            'records' => $records,
            'summary' => [
                'numerator' => $approvedRequests,
                'denominator' => $totalRequests,
                'percentage' => $percentage,
                'rejected' => $rejectedRequests,
                'formula' => 'Membership Conversion = (Approved Requests / Total Requests) * 100',
            ]
        ];
    }

    public function getReferralActivityDetail(AdminUser $admin, array $filters = []): array
    {
        $peersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($peersQuery, $admin);

        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $this->applyCircleFilterToUsersQuery($peersQuery, $filters['circle_id']);
        }

        if (!empty($filters['industry_id']) && $filters['industry_id'] !== 'all') {
            $industryId = $filters['industry_id'];
            $peersQuery->where(function ($q) use ($industryId) {
                $q->where('users.main_business_category_id', $industryId)
                  ->orWhereExists(function ($sub) use ($industryId) {
                      $sub->selectRaw(1)
                          ->from('circle_categories')
                          ->whereColumn('circle_categories.id', 'users.main_business_category_id')
                          ->where('circle_categories.parent_id', $industryId);
                  });
            });
        }

        // Denominator: Total Peers
        $totalPeersQuery = clone $peersQuery;
        if (!empty($filters['date_from'])) {
            $totalPeersQuery->where('users.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $totalPeersQuery->where('users.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        $totalPeers = (int) $totalPeersQuery->count();

        // Referrals base query
        if (!Schema::hasTable('referrals')) {
            return [
                'records' => [],
                'summary' => [
                    'numerator' => 0,
                    'denominator' => $totalPeers,
                    'percentage' => 0.0,
                    'formula' => 'Referral Activity (30d) = (Unique Referring Peers / Total Peers) * 100',
                ]
            ];
        }

        $referralQuery = DB::table('referrals')
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($peersQuery) {
                $q->whereIn('from_user_id', (clone $peersQuery)->select('id'))
                  ->orWhereIn('to_user_id', (clone $peersQuery)->select('id'));
            });

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            if (!empty($filters['date_from'])) {
                $referralQuery->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
            }
            if (!empty($filters['date_to'])) {
                $referralQuery->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
            }
        } else {
            $referralQuery->where('created_at', '>=', Carbon::now()->subDays(30));
        }

        $referralPeersCount = (int) (clone $referralQuery)->distinct('from_user_id')->count('from_user_id');
        $percentage = $totalPeers > 0 ? round(($referralPeersCount / $totalPeers) * 100, 1) : 0.0;

        $referralsList = Referral::query()
            ->whereIn('id', (clone $referralQuery)->select('id'))
            ->with([
                'fromUser:id,first_name,last_name,display_name,email,phone,company_name,main_business_category_id',
                'fromUser.mainBusinessCategory:id,name',
                'fromUser.circleMembers' => function($q) {
                    $q->where('status', 'approved')->whereNull('deleted_at');
                },
                'fromUser.circleMembers.circle:id,name',
                'toUser:id,first_name,last_name,display_name,email,phone,company_name'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $records = [];
        foreach ($referralsList as $ref) {
            $circleName = $ref->fromUser?->circleMembers?->map(fn($cm) => $cm->circle?->name)->filter()->unique()->implode(', ') ?: '—';
            $industryName = $ref->fromUser?->mainBusinessCategory?->name ?: '—';
            $records[] = [
                'id' => $ref->id,
                'from_user_name' => $ref->fromUser ? ($ref->fromUser->display_name ?: trim(($ref->fromUser->first_name ?? '') . ' ' . ($ref->fromUser->last_name ?? ''))) : '—',
                'referral_by' => $ref->fromUser ? ($ref->fromUser->display_name ?: trim(($ref->fromUser->first_name ?? '') . ' ' . ($ref->fromUser->last_name ?? ''))) : '—',
                'from_user_email' => $ref->fromUser?->email ?: '—',
                'from_user_phone' => $ref->fromUser?->phone ?: '—',
                'from_user_company' => $ref->fromUser?->company_name ?: '—',
                'to_user_name' => $ref->toUser ? ($ref->toUser->display_name ?: trim(($ref->toUser->first_name ?? '') . ' ' . ($ref->toUser->last_name ?? ''))) : '—',
                'referral_to' => $ref->toUser ? ($ref->toUser->display_name ?: trim(($ref->toUser->first_name ?? '') . ' ' . ($ref->toUser->last_name ?? ''))) : '—',
                'to_user_email' => $ref->toUser?->email ?: '—',
                'to_user_phone' => $ref->toUser?->phone ?: '—',
                'to_user_company' => $ref->toUser?->company_name ?: '—',
                'circle' => $circleName,
                'industry' => $industryName,
                'status' => $ref->is_deleted ? 'Deleted' : 'Active',
                'conversion_status' => 'Pending',
                'created_at' => $ref->created_at,
            ];
        }

        return [
            'records' => $records,
            'summary' => [
                'numerator' => $referralPeersCount,
                'denominator' => $totalPeers,
                'percentage' => $percentage,
                'formula' => 'Referral Activity (30d) = (Unique Referring Peers / Total Peers) * 100',
            ]
        ];
    }

    public function getCircleIndustryMappings(AdminUser $admin, ?string $selectedCircleId = null): array
    {
        $query = Circle::query();
        AdminCircleScope::applyToCirclesQuery($query, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $query->where('circles.id', $selectedCircleId);
        }
        $circles = $query->get();
        $circleIds = $circles->pluck('id')->all();

        // Start with direct database mappings
        $mappings = DB::table('circle_category_mappings')
            ->whereIn('circle_id', $circleIds ?: [''])
            ->get();

        $circleToIndustry = [];
        $industryToCircles = [];

        foreach ($mappings as $m) {
            $circleToIndustry[$m->circle_id][] = (int) $m->category_id;
            $industryToCircles[(int) $m->category_id][] = $m->circle_id;
        }

        // Apply keyword mapping heuristics for consistency with getIndustryCountForCircles
        $categoryMap = [
            'manufacturing' => 14,
            'engineering' => 14,
            'real estate' => 15,
            'construction' => 15,
            'infrastructure' => 15,
            'buildcon' => 15,
            'technology' => 16,
            'digital' => 16,
            'healthcare' => 17,
            'wellness' => 17,
            'life sciences' => 17,
            'healthopedia' => 17,
            'education' => 18,
            'training' => 18,
            'skill' => 18,
            'events' => 19,
            'fashion' => 19,
            'apparel' => 19,
            'lifestyle' => 19,
            'csr' => 20,
            'ngo' => 20,
            'nation-building' => 20,
            'cross-border' => 21,
            'global expansion' => 21,
            'sustainable' => 22,
            'esg' => 22,
            'import' => 23,
            'export' => 23,
            'global trade' => 23,
            'startup' => 24,
            'sme ipo' => 25,
            'ipo goal' => 25,
            'investors' => 26,
            'franchise' => 27,
            'licensing' => 27,
            'msme' => 28,
            'winners' => 28,
            'family business' => 29,
            'young' => 30,
            'leadership' => 31,
            'transformation' => 31,
        ];

        foreach ($circles as $circle) {
            $nameLower = mb_strtolower($circle->name);
            $matched = false;
            foreach ($categoryMap as $keyword => $catId) {
                if (str_contains($nameLower, $keyword)) {
                    if (!isset($circleToIndustry[$circle->id]) || !in_array($catId, $circleToIndustry[$circle->id], true)) {
                        $circleToIndustry[$circle->id][] = $catId;
                        $industryToCircles[$catId][] = $circle->id;
                    }
                    $matched = true;
                }
            }
            if (!$matched && !empty($circle->industry_tags)) {
                $tags = is_string($circle->industry_tags) ? json_decode($circle->industry_tags, true) : $circle->industry_tags;
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $tagLower = mb_strtolower($tag);
                        foreach ($categoryMap as $keyword => $catId) {
                            if (str_contains($tagLower, $keyword)) {
                                if (!isset($circleToIndustry[$circle->id]) || !in_array($catId, $circleToIndustry[$circle->id], true)) {
                                    $circleToIndustry[$circle->id][] = $catId;
                                    $industryToCircles[$catId][] = $circle->id;
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'circle_to_industry' => $circleToIndustry,
            'industry_to_circles' => $industryToCircles,
            'circle_ids' => $circleIds,
        ];
    }

    public function getIndustriesOverview(AdminUser $admin, array $filters = []): array
    {
        $circleMappings = $this->getCircleIndustryMappings($admin, $filters['circle_id'] ?? null);
        $circleToIndustry = $circleMappings['circle_to_industry'];
        $industryToCircles = $circleMappings['industry_to_circles'];
        $districtCircleIds = $circleMappings['circle_ids'];

        // Get all parent categories
        $categoriesQuery = DB::table('circle_categories')->whereNull('parent_id');
        if (!empty($filters['industry_id']) && $filters['industry_id'] !== 'all') {
            $categoriesQuery->where('id', $filters['industry_id']);
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $categoriesQuery->where('is_active', $filters['status'] === 'active');
        }
        $categories = $categoriesQuery->orderBy('name')->get();

        // Scoped users
        $usersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($usersQuery, $admin);
        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $this->applyCircleFilterToUsersQuery($usersQuery, $filters['circle_id']);
        }
        if (!empty($filters['date_from'])) {
            $usersQuery->where('users.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $usersQuery->where('users.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        $users = $usersQuery->get(['id', 'first_name', 'last_name', 'display_name', 'main_business_category_id', 'last_login_at', 'created_at', 'status']);
        $userIds = $users->pluck('id')->all();

        // Eager load circle memberships of users to check which circles they belong to
        $userMemberships = DB::table('circle_members')
            ->whereIn('user_id', $userIds ?: [''])
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('user_id');

        // Scoped circles
        $circlesQuery = Circle::query();
        AdminCircleScope::applyToCirclesQuery($circlesQuery, $admin);
        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $circlesQuery->where('id', $filters['circle_id']);
        }
        $circles = $circlesQuery->get();

        // Get deals, referrals, testimonials, P2P meetings to count
        $activityMap = $this->getUserActivityCounts($userIds);

        // Get active subscription amounts grouped by circle
        $subscriptions = CircleSubscription::query()
            ->whereIn('circle_id', $districtCircleIds ?: [''])
            ->where('status', 'active')
            ->get()
            ->groupBy('circle_id');

        $records = [];
        $activeIndustriesCount = 0;

        foreach ($categories as $cat) {
            $catId = (int) $cat->id;
            $industryCircleIds = $industryToCircles[$catId] ?? [];
            if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
                $industryCircleIds = array_intersect($industryCircleIds, [$filters['circle_id']]);
            }

            // A user belongs to this industry if their main_business_category_id is $catId OR they belong to a circle mapped to this industry
            $industryUsers = $users->filter(function ($u) use ($catId, $industryCircleIds, $userMemberships) {
                if ($u->main_business_category_id === $catId) {
                    return true;
                }
                $mems = $userMemberships->get($u->id);
                if ($mems) {
                    foreach ($mems as $m) {
                        if (in_array($m->circle_id, $industryCircleIds, true)) {
                            return true;
                        }
                    }
                }
                return false;
            });

            $industryUserIds = $industryUsers->pluck('id')->all();
            $membersCount = $industryUsers->count();

            // Active members (status = active)
            $activeMembersCount = $industryUsers->filter(function ($u) {
                return strtolower($u->status ?? '') === 'active';
            })->count();

            // Circles Count
            $circlesCount = count($industryCircleIds);
            if ($circlesCount > 0 || $membersCount > 0) {
                $activeIndustriesCount++;
            }

            // Circle Directors (distinct)
            $catCircles = $circles->whereIn('id', $industryCircleIds);
            $circleDirectors = $catCircles->pluck('director_user_id')->filter()->unique()->count();
            $industryDirectors = $catCircles->pluck('industry_director_user_id')->filter()->unique()->count();

            // Activity totals
            $dealsCount = 0;
            $referralsCount = 0;
            $testimonialsCount = 0;
            $p2pCount = 0;

            foreach ($industryUserIds as $uid) {
                $acts = $activityMap[$uid] ?? ['meetings' => 0, 'deals' => 0, 'referrals' => 0, 'testimonials' => 0];
                $dealsCount += $acts['deals'] ?? 0;
                $referralsCount += $acts['referrals'] ?? 0;
                $testimonialsCount += $acts['testimonials'] ?? 0;
                $p2pCount += $acts['meetings'] ?? 0;
            }

            // Revenue: sum of active subscriptions for circles in this industry
            $revenue = 0.0;
            foreach ($industryCircleIds as $cid) {
                if (isset($subscriptions[$cid])) {
                    $revenue += (float) $subscriptions[$cid]->sum('amount');
                }
            }

            $records[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'members_count' => $membersCount,
                'active_members_count' => $activeMembersCount,
                'circles_count' => $circlesCount,
                'circle_directors_count' => $circleDirectors,
                'industry_directors_count' => $industryDirectors,
                'deals_count' => $dealsCount,
                'referrals_count' => $referralsCount,
                'testimonials_count' => $testimonialsCount,
                'p2p_meetings_count' => $p2pCount,
                'revenue' => $revenue,
                'status' => $cat->is_active ? 'Active' : 'Inactive',
            ];
        }

        // Summary metrics
        $totalIndustries = DB::table('circle_categories')->whereNull('parent_id')->count();
        $totalMembers = $this->getPeersCount($admin, $filters['circle_id'] ?? null);
        $totalCircles = $this->getCirclesCount($admin, $filters['circle_id'] ?? null);
        $activeIndustries = $this->getIndustriesCount($admin, $filters['circle_id'] ?? null);

        return [
            'records' => $records,
            'summary' => [
                'total_industries' => $totalIndustries,
                'active_industries' => $activeIndustries,
                'total_members' => $totalMembers,
                'total_circles' => $totalCircles,
            ],
        ];
    }

    public function getIndustryDetail(AdminUser $admin, string $industryId, array $filters = []): array
    {
        $circleMappings = $this->getCircleIndustryMappings($admin, $filters['circle_id'] ?? null);
        $circleToIndustry = $circleMappings['circle_to_industry'];
        $industryToCircles = $circleMappings['industry_to_circles'];
        
        $cat = DB::table('circle_categories')->where('id', $industryId)->first();
        abort_unless($cat !== null, 404);

        $catId = (int) $industryId;
        $industryCircleIds = $industryToCircles[$catId] ?? [];
        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $industryCircleIds = array_intersect($industryCircleIds, [$filters['circle_id']]);
        }

        // Get all users in district
        $usersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($usersQuery, $admin);
        if (!empty($filters['circle_id']) && $filters['circle_id'] !== 'all') {
            $this->applyCircleFilterToUsersQuery($usersQuery, $filters['circle_id']);
        }
        if (!empty($filters['date_from'])) {
            $usersQuery->where('users.created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (!empty($filters['date_to'])) {
            $usersQuery->where('users.created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
        $users = $usersQuery->with(['mainBusinessCategory'])->get();
        $userIds = $users->pluck('id')->all();

        // Get memberships for role & circle details
        $memberships = DB::table('circle_members')
            ->join('circles', 'circles.id', '=', 'circle_members.circle_id')
            ->whereIn('circle_members.user_id', $userIds ?: [''])
            ->where('circle_members.status', 'approved')
            ->whereNull('circle_members.deleted_at')
            ->select('circle_members.*', 'circles.name as circle_name')
            ->get()
            ->groupBy('user_id');

        // Filter users belonging to this industry
        $industryUsers = $users->filter(function ($u) use ($catId, $industryCircleIds, $memberships) {
            if ($u->main_business_category_id === $catId) {
                return true;
            }
            $userMems = $memberships->get($u->id);
            if ($userMems) {
                foreach ($userMems as $m) {
                    if (in_array($m->circle_id, $industryCircleIds, true)) {
                        return true;
                    }
                }
            }
            return false;
        });

        $industryUserIds = $industryUsers->pluck('id')->all();

        // Format Members section
        $membersList = [];
        foreach ($industryUsers as $u) {
            $userMems = $memberships->get($u->id) ?? collect();
            $circleNames = $userMems->pluck('circle_name')->unique()->implode(', ') ?: '—';
            $roles = $userMems->map(fn($m) => ucfirst($m->role))->unique()->implode(', ') ?: 'Member';
            
            $joinedDate = null;
            if ($userMems->isNotEmpty()) {
                $joinedDate = $userMems->min('joined_at') ?: $userMems->min('created_at');
            } else {
                $joinedDate = $u->created_at;
            }

            $membersList[] = [
                'id' => $u->id,
                'name' => $u->display_name ?: trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'company' => $u->company_name ?: '—',
                'role' => $roles,
                'circle' => $circleNames,
                'status' => $u->status ?: 'active',
                'joined_date' => $joinedDate,
            ];
        }

        // Format Circles section
        $circlesQuery = Circle::query();
        AdminCircleScope::applyToCirclesQuery($circlesQuery, $admin);
        $circlesList = $circlesQuery->whereIn('id', $industryCircleIds ?: [''])
            ->with(['founder:id,display_name,first_name,last_name', 'director:id,display_name,first_name,last_name'])
            ->withCount(['members' => function ($q) {
                $q->where('status', 'approved')->whereNull('deleted_at');
            }])
            ->get();

        // Get subscription amounts
        $subscriptions = CircleSubscription::query()
            ->whereIn('circle_id', $industryCircleIds ?: [''])
            ->where('status', 'active')
            ->get()
            ->groupBy('circle_id');

        $circlesData = [];
        foreach ($circlesList as $c) {
            $founderName = $c->founder ? ($c->founder->display_name ?: trim(($c->founder->first_name ?? '') . ' ' . ($c->founder->last_name ?? ''))) : '—';
            $directorName = $c->director ? ($c->director->display_name ?: trim(($c->director->first_name ?? '') . ' ' . ($c->director->last_name ?? ''))) : '—';
            $rev = isset($subscriptions[$c->id]) ? (float) $subscriptions[$c->id]->sum('amount') : 0.0;

            $circlesData[] = [
                'id' => $c->id,
                'name' => $c->name,
                'founder' => $founderName,
                'director' => $directorName,
                'members_count' => $c->members_count,
                'revenue' => $rev,
                'status' => $c->status ?: 'active',
            ];
        }

        // Activity counts
        $activityMap = $this->getUserActivityCounts($industryUserIds);
        $dealsCount = 0;
        $referralsCount = 0;
        $testimonialsCount = 0;
        $p2pCount = 0;

        foreach ($industryUserIds as $uid) {
            $acts = $activityMap[$uid] ?? ['meetings' => 0, 'deals' => 0, 'referrals' => 0, 'testimonials' => 0];
            $dealsCount += $acts['deals'] ?? 0;
            $referralsCount += $acts['referrals'] ?? 0;
            $testimonialsCount += $acts['testimonials'] ?? 0;
            $p2pCount += $acts['meetings'] ?? 0;
        }

        // Summary counts
        $totalMembers = count($industryUserIds);
        $activeMembers = $industryUsers->filter(function ($u) {
            return strtolower($u->status ?? '') === 'active';
        })->count();
        $inactiveMembers = $totalMembers - $activeMembers;
        $activePercentage = $totalMembers > 0 ? round(($activeMembers / $totalMembers) * 100, 1) : 0.0;

        $totalCircles = count($industryCircleIds);
        $circleDirectors = $circlesList->pluck('director_user_id')->filter()->unique()->count();
        $industryDirectors = $circlesList->pluck('industry_director_user_id')->filter()->unique()->count();

        $totalRevenue = 0.0;
        foreach ($industryCircleIds as $cid) {
            if (isset($subscriptions[$cid])) {
                $totalRevenue += (float) $subscriptions[$cid]->sum('amount');
            }
        }

        return [
            'summary' => [
                'name' => $cat->name,
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'inactive_members' => $inactiveMembers,
                'active_percentage' => $activePercentage,
                'total_circles' => $totalCircles,
                'circle_directors' => $circleDirectors,
                'industry_directors' => $industryDirectors,
                'deals' => $dealsCount,
                'referrals' => $referralsCount,
                'testimonials' => $testimonialsCount,
                'meetings' => $p2pCount,
                'revenue' => $totalRevenue,
            ],
            'members' => $membersList,
            'circles' => $circlesData,
        ];
    }

    public function getHealthTrends(AdminUser $admin, ?string $selectedCircleId = null): array
    {
        $circleSubquery = Circle::query()->select('id');
        $this->scope->applyCirclesScope($circleSubquery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $circleSubquery->where('id', $selectedCircleId);
        }

        $peersQuery = User::query();
        $this->scope->applyUsersScope($peersQuery, $admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $this->applyCircleFilterToUsersQuery($peersQuery, $selectedCircleId);
        }

        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // 1. Active Members Trend
        $totalPeers = (int) $peersQuery->count();
        $activePeersCount = $totalPeers > 0 ? (clone $peersQuery)->where('status', 'active')->count() : 0;
        $currentActivePct = $totalPeers > 0 ? ($activePeersCount / $totalPeers) * 100 : 0.0;

        $totalPeersPrev = (int) (clone $peersQuery)->where('users.created_at', '<', $thirtyDaysAgo)->count();
        $activePeersPrev = $totalPeersPrev > 0 ? (clone $peersQuery)->where('users.created_at', '<', $thirtyDaysAgo)->where('status', 'active')->count() : 0;
        $prevActivePct = $totalPeersPrev > 0 ? ($activePeersPrev / $totalPeersPrev) * 100 : 0.0;

        $activeTrend = round($currentActivePct - $prevActivePct, 1);
        $activeTrendLabel = ($activeTrend >= 0 ? '+' : '') . $activeTrend . '% vs previous period';

        // 2. Leadership Filled Trend
        $totalCircles = (int) Circle::query()->whereIn('id', $circleSubquery)->count();
        $currentLeadPct = 0.0;
        if ($totalCircles > 0) {
            $rolesByCircle = DB::table('circle_members')
                ->whereIn('circle_id', $circleSubquery)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->select('circle_id',
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'chair' THEN user_id END) as has_chair"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'vice_chair' THEN user_id END) as has_vc"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'secretary' THEN user_id END) as has_sec")
                )
                ->groupBy('circle_id')
                ->get();
            $filledCount = $rolesByCircle->filter(fn($c) => $c->has_chair >= 1 && $c->has_vc >= 1 && $c->has_sec >= 1)->count();
            $currentLeadPct = ($filledCount / $totalCircles) * 100;
        }

        $totalCirclesPrev = (int) Circle::query()->whereIn('id', $circleSubquery)->where('created_at', '<', $thirtyDaysAgo)->count();
        $prevLeadPct = 0.0;
        if ($totalCirclesPrev > 0) {
            $rolesByCirclePrev = DB::table('circle_members')
                ->whereIn('circle_id', $circleSubquery)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->where('created_at', '<', $thirtyDaysAgo)
                ->select('circle_id',
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'chair' THEN user_id END) as has_chair"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'vice_chair' THEN user_id END) as has_vc"),
                    DB::raw("COUNT(DISTINCT CASE WHEN role = 'secretary' THEN user_id END) as has_sec")
                )
                ->groupBy('circle_id')
                ->get();
            $filledCountPrev = $rolesByCirclePrev->filter(fn($c) => $c->has_chair >= 1 && $c->has_vc >= 1 && $c->has_sec >= 1)->count();
            $prevLeadPct = ($filledCountPrev / $totalCirclesPrev) * 100;
        }
        $leadTrend = round($currentLeadPct - $prevLeadPct, 1);
        $leadTrendLabel = ($leadTrend >= 0 ? '+' : '') . $leadTrend . '% vs previous period';

        // 3. Membership Conversion Trend
        $joinRequestsQuery = CircleJoinRequest::query()->visibleToAdminUser($admin);
        if ($selectedCircleId && $selectedCircleId !== 'all') {
            $joinRequestsQuery->where('circle_id', $selectedCircleId);
        }
        
        $totalRequests = (int) $joinRequestsQuery->count();
        $approvedRequests = (int) (clone $joinRequestsQuery)->where('status', 'paid')->count();
        $currentConvPct = $totalRequests > 0 ? ($approvedRequests / $totalRequests) * 100 : 0.0;

        $totalRequestsPrev = (int) (clone $joinRequestsQuery)->where('created_at', '<', $thirtyDaysAgo)->count();
        $approvedRequestsPrev = (int) (clone $joinRequestsQuery)->where('created_at', '<', $thirtyDaysAgo)->where('status', 'paid')->count();
        $prevConvPct = $totalRequestsPrev > 0 ? ($approvedRequestsPrev / $totalRequestsPrev) * 100 : 0.0;

        $convTrend = round($currentConvPct - $prevConvPct, 1);
        $convTrendLabel = ($convTrend >= 0 ? '+' : '') . $convTrend . '% vs previous period';

        // 4. Referral Activity Trend
        $referralPeers = 0;
        if ($totalPeers > 0 && DB::getSchemaBuilder()->hasTable('referrals')) {
            $referralPeers = DB::table('referrals')
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->where('is_deleted', false)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($peersQuery) {
                    $q->whereIn('from_user_id', (clone $peersQuery)->select('id'))
                      ->orWhereIn('to_user_id', (clone $peersQuery)->select('id'));
                })
                ->distinct()
                ->count('from_user_id');
        }
        $currentRefPct = $totalPeers > 0 ? ($referralPeers / $totalPeers) * 100 : 0.0;

        $referralPeersPrev = 0;
        if ($totalPeers > 0 && DB::getSchemaBuilder()->hasTable('referrals')) {
            $referralPeersPrev = DB::table('referrals')
                ->whereBetween('created_at', [Carbon::now()->subDays(60), $thirtyDaysAgo])
                ->where('is_deleted', false)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($peersQuery) {
                    $q->whereIn('from_user_id', (clone $peersQuery)->select('id'))
                      ->orWhereIn('to_user_id', (clone $peersQuery)->select('id'));
                })
                ->distinct()
                ->count('from_user_id');
        }
        $prevRefPct = $totalPeers > 0 ? ($referralPeersPrev / $totalPeers) * 100 : 0.0;

        $refTrend = round($currentRefPct - $prevRefPct, 1);
        $refTrendLabel = ($refTrend >= 0 ? '+' : '') . $refTrend . '% vs previous period';

        return [
            'active_members' => [
                'trend' => $activeTrend,
                'trend_label' => $activeTrendLabel,
            ],
            'leadership_spots' => [
                'trend' => $leadTrend,
                'trend_label' => $leadTrendLabel,
            ],
            'membership_conversion' => [
                'trend' => $convTrend,
                'trend_label' => $convTrendLabel,
            ],
            'referral_activity' => [
                'trend' => $refTrend,
                'trend_label' => $refTrendLabel,
            ],
        ];
    }
}
