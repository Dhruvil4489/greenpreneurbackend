@extends('admin.layouts.app')

@section('title', 'Brand Partners Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Brand Partners Dashboard</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-people me-1"></i>All Partners</a>
        <a href="{{ route('admin.brand-partners.categories.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tags me-1"></i>Categories</a>
        <a href="{{ route('admin.brand-partners.offers') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gift me-1"></i>Offers</a>
        <a href="{{ route('admin.brand-partners.settings') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Settings</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if($stats['unique_views'] == 0)
    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-3 mb-4 bg-info-subtle text-info-emphasis rounded-3 p-3">
        <i class="bi bi-info-circle-fill fs-4"></i>
        <div>
            <div class="fw-bold">Analytics collection in progress</div>
            <span class="small opacity-75">Unique tracking metrics and CTR will populate automatically as new member interactions are recorded.</span>
        </div>
    </div>
@endif

<!-- Section 5: Quick Actions -->
<div class="card border-0 shadow-sm mb-4 bg-white rounded-3">
    <div class="card-body py-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <h6 class="fw-bold mb-0 text-secondary"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quick Actions</h6>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.brand-partners.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Partner</a>
            <a href="{{ route('admin.brand-partners.export', ['format' => 'csv']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
            <a href="{{ route('admin.brand-partners.export', ['format' => 'excel']) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
            <a href="{{ route('admin.brand-partners.create') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-gift me-1"></i>Create Offer</a>
            <a href="{{ route('admin.execution.communications') }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-bell me-1"></i>Send Notification</a>
        </div>
    </div>
</div>

<!-- Section 1: Primary KPI Cards -->
<div class="row g-3 mb-4">
    <!-- Total Partners -->
    <div class="col-lg col-md-4 col-sm-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Total Partners</span>
                    <h3 class="fw-bold mb-0 mt-2 text-dark fs-2">{{ number_format($stats['total_partners']) }}</h3>
                </div>
                <div class="bg-primary-subtle text-primary rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-building fs-5"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Active Offers -->
    <div class="col-lg col-md-4 col-sm-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Active Offers</span>
                    <h3 class="fw-bold mb-0 mt-2 text-success fs-2">{{ number_format($stats['active_offers']) }}</h3>
                </div>
                <div class="bg-success-subtle text-success rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-gift-fill fs-5"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Total Views -->
    <div class="col-lg col-md-4 col-sm-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Total Views</span>
                    <h3 class="fw-bold mb-0 mt-2 text-info fs-2">{{ number_format($stats['total_views']) }}</h3>
                    @if($stats['unique_views'] > 0)
                        <span class="text-muted small d-block mt-1" style="font-size: 11px;">Unique: {{ number_format($stats['unique_views']) }}</span>
                    @endif
                </div>
                <div class="bg-info-subtle text-info rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-eye-fill fs-5"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Website Clicks -->
    <div class="col-lg col-md-4 col-sm-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Total Clicks</span>
                    <h3 class="fw-bold mb-0 mt-2 text-primary fs-2">{{ number_format($stats['total_clicks']) }}</h3>
                    @if($stats['unique_views'] > 0)
                        <span class="text-muted small d-block mt-1" style="font-size: 11px;">Unique: {{ number_format($stats['unique_clicks']) }}</span>
                    @endif
                </div>
                <div class="bg-primary-subtle text-primary rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-link-45deg fs-5"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Unique CTR -->
    @if($stats['unique_views'] > 0)
        <div class="col-lg col-md-4 col-sm-6 col-12">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Unique CTR</span>
                        <h3 class="fw-bold mb-0 mt-2 text-warning fs-2">{{ $stats['ctr'] }}%</h3>
                    </div>
                    <div class="bg-warning-subtle text-warning rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="bi bi-percent fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    @endif
    <!-- Redemptions -->
    <div class="col-lg col-md-4 col-sm-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white rounded-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 11px; letter-spacing: 0.5px;">Redemptions</span>
                    <h3 class="fw-bold mb-0 mt-2 text-success fs-2">{{ number_format($stats['total_redemptions']) }}</h3>
                    @if($stats['unique_views'] > 0)
                        <span class="text-muted small d-block mt-1" style="font-size: 11px;">Conv. Rate: {{ $stats['conversion_rate'] }}%</span>
                    @endif
                </div>
                <div class="bg-success-subtle text-success rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-ticket-perforated-fill fs-5"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section 2: Secondary Statistics -->
<div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-3 mb-4">
    <!-- Featured Partners -->
    <div class="col">
        <div class="card border-0 shadow-sm p-3 bg-white text-center rounded-3">
            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 10px; letter-spacing: 0.5px;">Featured</span>
            <div class="fw-bold text-dark fs-4 mt-2">{{ number_format($stats['featured_partners']) }}</div>
        </div>
    </div>
    <!-- Sponsored Partners -->
    <div class="col">
        <div class="card border-0 shadow-sm p-3 bg-white text-center rounded-3">
            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 10px; letter-spacing: 0.5px;">Sponsored</span>
            <div class="fw-bold text-dark fs-4 mt-2">{{ number_format($stats['sponsored_partners']) }}</div>
        </div>
    </div>
    <!-- Expired Offers -->
    <div class="col">
        <div class="card border-0 shadow-sm p-3 bg-white text-center rounded-3">
            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 10px; letter-spacing: 0.5px;">Expired Offers</span>
            <div class="fw-bold text-danger fs-4 mt-2">{{ number_format($stats['expired_offers']) }}</div>
        </div>
    </div>
    <!-- Inactive Partners -->
    <div class="col">
        <div class="card border-0 shadow-sm p-3 bg-white text-center rounded-3">
            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 10px; letter-spacing: 0.5px;">Inactive</span>
            <div class="fw-bold text-secondary fs-4 mt-2">{{ number_format($stats['inactive_partners']) }}</div>
        </div>
    </div>
    <!-- Saved Partners -->
    <div class="col">
        <div class="card border-0 shadow-sm p-3 bg-white text-center rounded-3">
            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 10px; letter-spacing: 0.5px;">Saves</span>
            <div class="fw-bold text-info fs-4 mt-2">{{ number_format($stats['saved_partners']) }}</div>
        </div>
    </div>
</div>

<!-- Section 3: Performance Highlights -->
<div class="row g-3 mb-4">
    <!-- Top Performing Partner -->
    <div class="col-md-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white d-flex flex-row align-items-center justify-content-between rounded-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success-subtle p-3 text-success">
                    <i class="bi bi-graph-up-arrow fs-2"></i>
                </div>
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Top Performing Partner</span>
                    <h5 class="fw-bold mb-0 text-dark mt-1">
                        {{ $stats['top_performing_partner']?->name ?? 'None Yet' }}
                    </h5>
                    <span class="text-muted small">Most active engagement with {{ number_format($stats['top_performing_clicks']) }} clicks.</span>
                </div>
            </div>
            @if($stats['top_performing_partner'])
                <a href="{{ route('admin.brand-partners.show', $stats['top_performing_partner']) }}" class="btn btn-sm btn-outline-secondary">Details</a>
            @endif
        </div>
    </div>
    <!-- Most Saved Partner -->
    <div class="col-md-6 col-12">
        <div class="card border-0 shadow-sm p-3 h-100 bg-white d-flex flex-row align-items-center justify-content-between rounded-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger-subtle p-3 text-danger">
                    <i class="bi bi-bookmark-heart-fill fs-2"></i>
                </div>
                <div>
                    <span class="text-muted small text-uppercase fw-semibold">Most Bookmarked Partner</span>
                    <h5 class="fw-bold mb-0 text-dark mt-1">
                        {{ $stats['most_saved_partner']?->name ?? 'None Yet' }}
                    </h5>
                    <span class="text-muted small">Saved in favorites by {{ number_format($stats['most_saved_count']) }} peers.</span>
                </div>
            </div>
            @if($stats['most_saved_partner'])
                <a href="{{ route('admin.brand-partners.show', $stats['most_saved_partner']) }}" class="btn btn-sm btn-outline-secondary">Details</a>
            @endif
        </div>
    </div>
</div>

<!-- Section 4: Analytics Charts -->
<div class="row g-3">
    <!-- Traffic Chart (Line) -->
    <div class="col-md-8 col-12">
        <div class="card border-0 shadow-sm p-4 bg-white rounded-3">
            <h5 class="fw-bold mb-3 text-secondary">Daily Traffic (Last 30 Days)</h5>
            <div style="height: 300px; position: relative;">
                <canvas id="trafficChartCanvas"></canvas>
            </div>
        </div>
    </div>
    <!-- Top Categories (Doughnut) -->
    <div class="col-md-4 col-12">
        <div class="card border-0 shadow-sm p-4 bg-white h-100 rounded-3">
            <h5 class="fw-bold mb-3 text-secondary">Top Categories</h5>
            <div style="height: 250px; position: relative;" class="d-flex align-items-center justify-content-center">
                <canvas id="categoriesChartCanvas"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ChartJS Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartsData = @json($charts);

        // 1. Traffic Chart (Line)
        const trafficDates = chartsData.traffic_chart.map(item => item.date);
        const trafficViews = chartsData.traffic_chart.map(item => item.views);
        const trafficClicks = chartsData.traffic_chart.map(item => item.clicks);

        new Chart(document.getElementById('trafficChartCanvas'), {
            type: 'line',
            data: {
                labels: trafficDates,
                datasets: [
                    {
                        label: 'Views',
                        data: trafficViews,
                        borderColor: '#0dcaf0',
                        backgroundColor: '#0dcaf010',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Clicks',
                        data: trafficClicks,
                        borderColor: '#0d6efd',
                        backgroundColor: '#0d6efd10',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });

        // 2. Categories Chart (Doughnut)
        const catLabels = chartsData.top_categories.map(item => item.name);
        const catCounts = chartsData.top_categories.map(item => item.count);

        new Chart(document.getElementById('categoriesChartCanvas'), {
            type: 'doughnut',
            data: {
                labels: catLabels.length ? catLabels : ['No Data'],
                datasets: [{
                    data: catCounts.length ? catCounts : [1],
                    backgroundColor: ['#4A90E2', '#F5A623', '#E28499', '#7ED321', '#BD10E0', '#CCCCCC']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    });
</script>
@endsection
