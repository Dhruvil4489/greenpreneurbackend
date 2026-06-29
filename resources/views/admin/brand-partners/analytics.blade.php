@extends('admin.layouts.app')

@section('title', 'Brand Partners Analytics')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Analytics &amp; Performance Reports</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.dashboard') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">All Partners</a>
    </div>
</div>

<div class="row g-3">
    <!-- Click Conversion Rate widget -->
    <div class="col-md-4 col-12">
        <div class="card border-0 shadow-sm p-4 bg-white text-center h-100">
            <h6 class="text-uppercase text-secondary fw-semibold mb-2">Redeem Conversion Rate</h6>
            @php
                $conversionRate = $stats['total_website_clicks'] > 0 ? round(($stats['total_redemptions'] / $stats['total_website_clicks']) * 100, 2) : 0;
            @endphp
            <div class="display-3 fw-bold text-primary my-3">{{ $conversionRate }}%</div>
            <p class="text-muted small mb-0">Percentage of website clicks that proceed to redeem a coupon code code.</p>
        </div>
    </div>
    <!-- Website Clicks CTR widget -->
    <div class="col-md-4 col-12">
        <div class="card border-0 shadow-sm p-4 bg-white text-center h-100">
            <h6 class="text-uppercase text-secondary fw-semibold mb-2">Website CTR</h6>
            @php
                $websiteCtr = $stats['total_views'] > 0 ? round(($stats['total_website_clicks'] / $stats['total_views']) * 100, 2) : 0;
            @endphp
            <div class="display-3 fw-bold text-success my-3">{{ $websiteCtr }}%</div>
            <p class="text-muted small mb-0">Percentage of brand views that clicked through to visit the website URL.</p>
        </div>
    </div>
    <!-- Redeem Clicks CTR widget -->
    <div class="col-md-4 col-12">
        <div class="card border-0 shadow-sm p-4 bg-white text-center h-100">
            <h6 class="text-uppercase text-secondary fw-semibold mb-2">Redeem CTR</h6>
            @php
                $redeemCtr = $stats['total_views'] > 0 ? round(($stats['total_redemptions'] / $stats['total_views']) * 100, 2) : 0;
            @endphp
            <div class="display-3 fw-bold text-info my-3">{{ $redeemCtr }}%</div>
            <p class="text-muted small mb-0">Percentage of brand views that converted directly into coupon redemptions.</p>
        </div>
    </div>

    <!-- Category Breakdown and Performance Graph -->
    <div class="col-md-6 col-12 mt-3">
        <div class="card border-0 shadow-sm p-4 bg-white h-100">
            <h5 class="fw-bold mb-3 text-secondary">Top Performing Categories</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th class="text-center">Partners Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($charts['top_categories'] as $cat)
                            <tr>
                                <td>
                                    <span class="fw-bold text-dark">{{ $cat['name'] }}</span>
                                </td>
                                <td class="text-center fw-semibold text-primary">{{ $cat['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Last 6 Months Metrics -->
    <div class="col-md-6 col-12 mt-3">
        <div class="card border-0 shadow-sm p-4 bg-white h-100">
            <h5 class="fw-bold mb-3 text-secondary">Historical Overview</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-center">Views</th>
                            <th class="text-center">Clicks</th>
                            <th class="text-center">CTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($charts['monthly_performance'] as $month)
                            @php
                                $monthCtr = $month['views'] > 0 ? round(($month['clicks'] / $month['views']) * 100, 2) : 0;
                            @endphp
                            <tr>
                                <td class="fw-medium text-dark">{{ $month['month'] }}</td>
                                <td class="text-center">{{ number_format($month['views']) }}</td>
                                <td class="text-center">{{ number_format($month['clicks']) }}</td>
                                <td class="text-center fw-bold text-primary">{{ $monthCtr }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
