<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use App\Services\Api\Ded\DashboardAggregationService;
use App\Services\Api\Ded\DistrictAnalyticsService;
use Illuminate\Http\Request;

class DedDashboardController extends Controller
{
    public function __construct(
        private readonly DedApiService $ded,
        private readonly DashboardAggregationService $aggregation,
        private readonly DistrictAnalyticsService $analytics
    ) {}

    public function show(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $admin = $this->ded->admin($request);
        $circleId = $request->query('circle_id');
        $data = $this->aggregation->getDashboardData($admin, $circleId);

        $healthTrends = $this->analytics->getHealthTrends($admin, $circleId);

        // Format master_overview
        $masterOverview = [];

        // 1. Health KPIs
        $masterOverview['active_members'] = [
            'value' => (float) ($data['health_score']['active_members_pct'] ?? 0),
            'trend' => (float) ($healthTrends['active_members']['trend'] ?? 0),
            'trend_label' => (string) ($healthTrends['active_members']['trend_label'] ?? ''),
        ];
        $masterOverview['leadership_spots_filled'] = [
            'value' => (float) ($data['health_score']['leadership_filled_pct'] ?? 0),
            'trend' => (float) ($healthTrends['leadership_spots']['trend'] ?? 0),
            'trend_label' => (string) ($healthTrends['leadership_spots']['trend_label'] ?? ''),
        ];
        $masterOverview['membership_conversion'] = [
            'value' => (float) ($data['health_score']['membership_conversion_pct'] ?? 0),
            'trend' => (float) ($healthTrends['membership_conversion']['trend'] ?? 0),
            'trend_label' => (string) ($healthTrends['membership_conversion']['trend_label'] ?? ''),
        ];
        $masterOverview['referral_activity'] = [
            'value' => (float) ($data['health_score']['referral_activity_pct'] ?? 0),
            'trend' => (float) ($healthTrends['referral_activity']['trend'] ?? 0),
            'trend_label' => (string) ($healthTrends['referral_activity']['trend_label'] ?? ''),
        ];

        // 2. Existing KPIs
        foreach ($data['master_overview'] ?? [] as $key => $item) {
            $trendStr = $item['trend'] ?? '';
            $masterOverview[$key] = [
                'value' => (float) ($item['value'] ?? 0),
                'trend' => $this->parseTrendValue($trendStr),
                'trend_label' => $trendStr ?: 'No Change',
            ];
        }

        $data['master_overview'] = $masterOverview;

        return $this->ded->success($data, 'DED dashboard loaded.');
    }

    private function parseTrendValue(string $trendStr): float
    {
        $trendStr = trim($trendStr);
        if ($trendStr === '' || strtolower($trendStr) === 'no change') {
            return 0.0;
        }

        $multiplier = 1.0;
        if (str_contains($trendStr, '↓')) {
            $multiplier = -1.0;
        }

        $cleaned = str_replace(',', '', $trendStr);
        if (preg_match('/-?\d+(\.\d+)?/', $cleaned, $matches)) {
            return (float) $matches[0] * $multiplier;
        }

        return 0.0;
    }

    public function circles(Request $request)
    {
        $admin = $this->ded->admin($request);
        $circlesQuery = \App\Models\Circle::query();
        \App\Support\AdminCircleScope::applyToCirclesQuery($circlesQuery, $admin);
        $list = $circlesQuery->orderBy('name')->get(['id', 'name']);

        return $this->ded->success($list, 'Circles loaded.');
    }

    public function activeMembers(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'status' => $request->query('status'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getActiveMembersDetail($admin, $filters);

        return $this->ded->success($data['records'], 'Active members loaded.', [
            'summary' => $data['summary']
        ]);
    }

    public function leadershipSpots(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'status' => $request->query('status'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getLeadershipSpotsFilledDetail($admin, $filters);

        return $this->ded->success($data['records'], 'Leadership spots loaded.', [
            'summary' => $data['summary']
        ]);
    }

    public function membershipConversion(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getMembershipConversionDetail($admin, $filters);

        $response = [
            'total_requests' => (int) ($data['summary']['denominator'] ?? 0),
            'approved' => (int) ($data['summary']['numerator'] ?? 0),
            'rejected' => (int) ($data['summary']['rejected'] ?? 0),
            'conversion_percentage' => (float) ($data['summary']['percentage'] ?? 0.0),
            'records' => $data['records'],
        ];

        return $this->ded->success($response, 'Membership conversion loaded.', [
            'summary' => $data['summary']
        ]);
    }

    public function referralActivity(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getReferralActivityDetail($admin, $filters);

        return $this->ded->success($data['records'], 'Referral activity loaded.', [
            'summary' => $data['summary']
        ]);
    }

    public function industries(Request $request)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'status' => $request->query('status'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getIndustriesOverview($admin, $filters);

        $formatted = collect($data['records'])->map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'industry_name' => $item['name'],
                'total_members' => $item['members_count'],
                'members_count' => $item['members_count'],
                'active_members' => $item['active_members_count'],
                'active_members_count' => $item['active_members_count'],
                'total_circles' => $item['circles_count'],
                'circles_count' => $item['circles_count'],
                'industry_directors' => $item['industry_directors_count'],
                'industry_directors_count' => $item['industry_directors_count'],
                'referrals' => $item['referrals_count'],
                'referrals_count' => $item['referrals_count'],
                'p2p_meetings' => $item['p2p_meetings_count'],
                'p2p_meetings_count' => $item['p2p_meetings_count'],
                'testimonials' => $item['testimonials_count'],
                'testimonials_count' => $item['testimonials_count'],
                'business_deals' => $item['deals_count'],
                'deals_count' => $item['deals_count'],
                'revenue' => $item['revenue'],
                'status' => $item['status'],
            ];
        })->all();

        return $this->ded->success($formatted, 'Industries overview loaded.', [
            'summary' => $data['summary']
        ]);
    }

    public function industryDetail(Request $request, string $id)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getIndustryDetail($admin, $id, $filters);

        return $this->ded->success($data, 'Industry detail loaded.');
    }

    public function industryDirectors(Request $request)
    {
        return $this->handleLeadershipRole($request, 'industry_director');
    }

    public function founders(Request $request)
    {
        return $this->handleLeadershipRole($request, 'founder');
    }

    public function directors(Request $request)
    {
        return $this->handleLeadershipRole($request, 'director');
    }

    public function chairs(Request $request)
    {
        return $this->handleLeadershipRole($request, 'chair');
    }

    public function viceChairs(Request $request)
    {
        return $this->handleLeadershipRole($request, 'vice_chair');
    }

    public function secretaries(Request $request)
    {
        return $this->handleLeadershipRole($request, 'secretary');
    }

    public function members(Request $request)
    {
        return $this->handleLeadershipRole($request, 'member');
    }

    private function handleLeadershipRole(Request $request, string $role)
    {
        $request->validate([
            'circle_id' => ['nullable', 'string'],
            'industry_id' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ]);

        $admin = $this->ded->admin($request);
        $filters = [
            'circle_id' => $request->query('circle_id'),
            'industry_id' => $request->query('industry_id'),
            'status' => $request->query('status'),
            'date_from' => $request->query('from_date') ?? $request->query('date_from'),
            'date_to' => $request->query('to_date') ?? $request->query('date_to'),
        ];

        $data = $this->analytics->getLeadershipRoleDetails($admin, $role, $filters);

        $summary = [
            'total_count' => (int) ($data['summary']['total_count'] ?? 0),
            'revenue_contribution' => (float) ($data['summary']['revenue_contribution'] ?? 0.0),
            'members_managed' => (int) ($data['summary']['total_members_managed'] ?? 0),
            'circles_covered' => (int) ($data['summary']['total_circles_covered'] ?? 0),
            'district_coverage' => (float) ($data['summary']['district_coverage_pct'] ?? 0.0),
        ];

        return $this->ded->success([
            'summary' => $summary,
            'records' => $data['records'],
        ], ucfirst(str_replace('_', ' ', $role)) . ' details loaded.');
    }
}
