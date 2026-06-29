<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BrandPartners\BrandPartnerAnalyticsService;
use Illuminate\View\View;

class BrandPartnerAnalyticsController extends Controller
{
    public function __construct(
        private readonly BrandPartnerAnalyticsService $analyticsService
    ) {
    }

    public function index(): View
    {
        $stats = $this->analyticsService->getDashboardStats();
        $charts = $this->analyticsService->getDashboardCharts();

        return view('admin.brand-partners.dashboard', compact('stats', 'charts'));
    }

    public function detailedReport(): View
    {
        $stats = $this->analyticsService->getDashboardStats();
        $charts = $this->analyticsService->getDashboardCharts();
        
        return view('admin.brand-partners.analytics', compact('stats', 'charts'));
    }
}
