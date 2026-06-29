<?php

namespace App\Services\BrandPartners;

use App\Models\BrandPartner;
use App\Models\BrandPartnerCategory;
use App\Models\BrandPartnerClick;
use App\Models\BrandPartnerView;
use App\Models\BrandPartnerSaved;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BrandPartnerAnalyticsService
{
    public function getDashboardStats(): array
    {
        $now = Carbon::now();

        // Cards statistics
        $totalPartners = BrandPartner::count();
        $featuredPartners = BrandPartner::where('is_featured', true)->count();
        $sponsoredPartners = BrandPartner::where('is_sponsored', true)->count();
        
        $activeOffers = BrandPartner::where('is_active', true)
            ->whereNotNull('offer_title')
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $now);
            })
            ->count();

        $expiredOffers = BrandPartner::whereNotNull('offer_title')
            ->where('valid_to', '<', $now)
            ->count();

        $inactivePartners = BrandPartner::where('is_active', false)->count();
        
        // Views tracking
        $totalViews = BrandPartnerView::count();
        $uniqueViews = BrandPartnerView::selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS VARCHAR), ip_address, session_id)) as count')
            ->first()->count;

        // Clicks tracking
        $totalClicks = BrandPartnerClick::count();
        $uniqueClicks = BrandPartnerClick::selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS VARCHAR), ip_address, session_id)) as count')
            ->first()->count;

        // Force unique clicks to be <= unique views for mathematical sanity
        if ($uniqueClicks > $uniqueViews) {
            $uniqueClicks = $uniqueViews;
        }

        $totalWebsiteClicks = BrandPartnerClick::where('click_type', 'website')->count();
        $uniqueWebsiteClicks = BrandPartnerClick::where('click_type', 'website')
            ->selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS VARCHAR), ip_address, session_id)) as count')
            ->first()->count;

        $totalRedemptions = BrandPartnerClick::where('click_type', 'redeem')->count();
        $savedPartners = BrandPartnerSaved::count();

        // CTR Safety Validation
        $ctr = 0;
        if ($uniqueViews > 0) {
            $ctr = ($uniqueClicks / $uniqueViews) * 100;
        }
        if ($ctr > 100) {
            \Illuminate\Support\Facades\Log::warning("Brand Partners CTR calculation exceeded 100%: {$ctr}%. Capping to 100%. Unique Clicks: {$uniqueClicks}, Unique Views: {$uniqueViews}");
            $ctr = 100;
        }
        $ctr = round($ctr, 2);

        // Conversion Rate Safety Validation
        $conversionRate = 0;
        if ($uniqueClicks > 0) {
            $conversionRate = ($totalRedemptions / $uniqueClicks) * 100;
        }
        if ($conversionRate > 100) {
            \Illuminate\Support\Facades\Log::warning("Brand Partners Conversion Rate exceeded 100%: {$conversionRate}%. Capping to 100%. Redemptions: {$totalRedemptions}, Unique Clicks: {$uniqueClicks}");
            $conversionRate = 100;
        }
        $conversionRate = round($conversionRate, 2);

        // Top Performing Partner (Most Clicked)
        $topPartnerRow = BrandPartnerClick::select('brand_partner_id', DB::raw('COUNT(*) as click_count'))
            ->groupBy('brand_partner_id')
            ->orderByDesc('click_count')
            ->first();
        $topPerformingPartner = $topPartnerRow ? BrandPartner::find($topPartnerRow->brand_partner_id) : null;

        // Most Saved Partner
        $mostSavedRow = BrandPartnerSaved::select('brand_partner_id', DB::raw('COUNT(*) as save_count'))
            ->groupBy('brand_partner_id')
            ->orderByDesc('save_count')
            ->first();
        $mostSavedPartner = $mostSavedRow ? BrandPartner::find($mostSavedRow->brand_partner_id) : null;

        return [
            'total_partners' => $totalPartners,
            'featured_partners' => $featuredPartners,
            'sponsored_partners' => $sponsoredPartners,
            'active_offers' => $activeOffers,
            'expired_offers' => $expiredOffers,
            'inactive_partners' => $inactivePartners,
            'total_views' => $totalViews,
            'unique_views' => $uniqueViews,
            'total_clicks' => $totalClicks,
            'unique_clicks' => $uniqueClicks,
            'total_website_clicks' => $totalWebsiteClicks,
            'unique_website_clicks' => $uniqueWebsiteClicks,
            'total_redemptions' => $totalRedemptions,
            'saved_partners' => $savedPartners,
            'ctr' => $ctr,
            'conversion_rate' => $conversionRate,
            'top_performing_partner' => $topPerformingPartner,
            'top_performing_clicks' => $topPartnerRow->click_count ?? 0,
            'most_saved_partner' => $mostSavedPartner,
            'most_saved_count' => $mostSavedRow->save_count ?? 0,
        ];
    }

    public function getDashboardCharts(): array
    {
        $days30Ago = Carbon::now()->subDays(30)->startOfDay();
        $months6Ago = Carbon::now()->subMonths(6)->startOfMonth();

        // Daily traffic (views and clicks for the last 30 days)
        $dailyViews = BrandPartnerView::selectRaw('DATE(viewed_at) as date, COUNT(*) as count')
            ->where('viewed_at', '>=', $days30Ago)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $dailyClicks = BrandPartnerClick::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', $days30Ago)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill in missing dates for the last 30 days
        $trafficChart = [];
        for ($i = 30; $i >= 0; $i--) {
            $dateStr = Carbon::now()->subDays($i)->format('Y-m-d');
            $trafficChart[] = [
                'date' => $dateStr,
                'views' => $dailyViews[$dateStr] ?? 0,
                'clicks' => $dailyClicks[$dateStr] ?? 0,
            ];
        }

        // Top Categories by partners count
        $topCategories = BrandPartnerCategory::withCount('brandPartners')
            ->orderByDesc('brand_partners_count')
            ->limit(5)
            ->get()
            ->map(fn($cat) => [
                'name' => $cat->name,
                'count' => $cat->brand_partners_count,
            ])
            ->toArray();

        // Monthly performance (last 6 months)
        $monthlyViews = BrandPartnerView::selectRaw("TO_CHAR(viewed_at, 'YYYY-MM') as month, COUNT(*) as count")
            ->where('viewed_at', '>=', $months6Ago)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->pluck('count', 'month')
            ->toArray();

        $monthlyClicks = BrandPartnerClick::selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as count")
            ->where('created_at', '>=', $months6Ago)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->pluck('count', 'month')
            ->toArray();

        $monthlyPerformance = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStr = Carbon::now()->subMonths($i)->format('Y-m');
            $monthLabel = Carbon::now()->subMonths($i)->format('M Y');
            $monthlyPerformance[] = [
                'month' => $monthLabel,
                'views' => $monthlyViews[$monthStr] ?? 0,
                'clicks' => $monthlyClicks[$monthStr] ?? 0,
            ];
        }

        return [
            'traffic_chart' => $trafficChart,
            'top_categories' => $topCategories,
            'monthly_performance' => $monthlyPerformance,
        ];
    }

    public function getPartnerAnalytics(string $partnerId): array
    {
        $views = BrandPartnerView::where('brand_partner_id', $partnerId)->count();
        $uniqueViews = BrandPartnerView::where('brand_partner_id', $partnerId)
            ->selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS VARCHAR), ip_address, session_id)) as count')
            ->first()->count;
        
        $clicksQuery = BrandPartnerClick::where('brand_partner_id', $partnerId);
        $totalClicks = (clone $clicksQuery)->count();
        $uniqueClicks = (clone $clicksQuery)
            ->selectRaw('COUNT(DISTINCT COALESCE(CAST(user_id AS VARCHAR), ip_address, session_id)) as count')
            ->first()->count;

        // Force unique clicks to be <= unique views for mathematical sanity
        if ($uniqueClicks > $uniqueViews) {
            $uniqueClicks = $uniqueViews;
        }

        $websiteClicks = (clone $clicksQuery)->where('click_type', 'website')->count();
        $redeems = (clone $clicksQuery)->where('click_type', 'redeem')->count();
        $shares = (clone $clicksQuery)->where('click_type', 'share')->count();
        $calls = (clone $clicksQuery)->where('click_type', 'call')->count();
        $emails = (clone $clicksQuery)->where('click_type', 'email')->count();

        $saves = BrandPartnerSaved::where('brand_partner_id', $partnerId)->count();

        // Unique CTR Safety Validation
        $ctr = 0;
        if ($uniqueViews > 0) {
            $ctr = ($uniqueClicks / $uniqueViews) * 100;
        }
        if ($ctr > 100) {
            \Illuminate\Support\Facades\Log::warning("Brand Partner {$partnerId} CTR calculation exceeded 100%: {$ctr}%. Capping to 100%.");
            $ctr = 100;
        }
        $ctr = round($ctr, 2);

        // Conversion Rate Safety Validation
        $conversionRate = 0;
        if ($uniqueClicks > 0) {
            $conversionRate = ($redeems / $uniqueClicks) * 100;
        }
        if ($conversionRate > 100) {
            \Illuminate\Support\Facades\Log::warning("Brand Partner {$partnerId} Conversion Rate exceeded 100%: {$conversionRate}%. Capping to 100%.");
            $conversionRate = 100;
        }
        $conversionRate = round($conversionRate, 2);

        return [
            'views' => $views,
            'unique_views' => $uniqueViews,
            'clicks' => $totalClicks,
            'unique_clicks' => $uniqueClicks,
            'website_clicks' => $websiteClicks,
            'redeem_clicks' => $redeems,
            'shares' => $shares,
            'calls' => $calls,
            'emails' => $emails,
            'saves' => $saves,
            'ctr' => $ctr,
            'conversion_rate' => $conversionRate,
        ];
    }
}
