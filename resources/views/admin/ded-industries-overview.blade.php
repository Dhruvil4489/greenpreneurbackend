@extends('admin.layouts.app')

@section('title', 'DED - Industries Overview')

@section('content')
<style>
    .kpi-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.06);
        transition: all 0.25s ease-in-out;
        cursor: pointer;
    }
    .kpi-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
        border-color: #0d6efd;
    }
    .text-primary-gradient {
        background: linear-gradient(45deg, #0d6efd, #0dcaf0);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .badge-active { background-color: #d1e7dd; color: #0f5132; }
    .badge-inactive { background-color: #f8d7da; color: #842029; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-1">Ecosystem Industry Breakdown</p>
        <h3 class="mb-0 fw-bold text-primary-gradient">Industries Overview</h3>
    </div>
    <div class="d-flex gap-2">
        @if ($districtName)
            <span class="fs-6 py-2 px-3 badge bg-primary text-white border border-primary-subtle align-self-center">District: {{ $districtName }}</span>
        @endif
        <a href="{{ route('admin.ded.dashboard', ['circle_id' => $filters['circle_id'] ?? 'all']) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Industry Overview Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Industries</span>
            <h2 class="my-2 fw-bold text-dark">{{ number_format($summary['total_industries'] ?? 0) }}</h2>
            <span class="text-muted small">registered in system</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Active Industries</span>
            <h2 class="my-2 fw-bold text-success">{{ number_format($summary['active_industries'] ?? 0) }}</h2>
            <span class="text-muted small">represented in district</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Industry Members</span>
            <h2 class="my-2 fw-bold text-primary">{{ number_format($summary['total_members'] ?? 0) }}</h2>
            <span class="text-muted small">peers in scoped district</span>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Industry Circles</span>
            <h2 class="my-2 fw-bold text-info">{{ number_format($summary['total_circles'] ?? 0) }}</h2>
            <span class="text-muted small">circles in scoped district</span>
        </div>
    </div>
</div>

<!-- Filters Section -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" action="{{ route('admin.ded.dashboard.industries') }}" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label for="industryFilter" class="form-label small text-muted mb-1 fw-bold">Industry</label>
            <select id="industryFilter" name="industry_id" class="form-select">
                <option value="all" @selected(($filters['industry_id'] ?? '') === '' || ($filters['industry_id'] ?? '') === 'all')>All Industries</option>
                @foreach ($industries as $ind)
                    <option value="{{ $ind->id }}" @selected(($filters['industry_id'] ?? '') === (string) $ind->id)>
                        {{ $ind->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label for="circleFilter" class="form-label small text-muted mb-1 fw-bold">Circle</label>
            <select id="circleFilter" name="circle_id" class="form-select">
                <option value="all" @selected(($filters['circle_id'] ?? '') === '' || ($filters['circle_id'] ?? '') === 'all')>All Circles</option>
                @foreach ($districtCircles as $c)
                    <option value="{{ $c->id }}" @selected(($filters['circle_id'] ?? '') === $c->id)>
                        {{ $c->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="statusFilter" class="form-label small text-muted mb-1 fw-bold">Status</label>
            <select id="statusFilter" name="status" class="form-select">
                <option value="all" @selected(($filters['status'] ?? '') === '' || ($filters['status'] ?? '') === 'all')>All</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <label for="dateFrom" class="form-label small text-muted mb-1 fw-bold">Date From</label>
            <input type="date" id="dateFrom" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label for="dateTo" class="form-label small text-muted mb-1 fw-bold">Date To</label>
            <input type="date" id="dateTo" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.ded.dashboard.industries') }}" class="btn btn-outline-secondary px-4">Reset Filters</a>
            <button type="submit" class="btn btn-primary px-4">Apply Filters</button>
        </div>
    </form>
</div>

<!-- Detailed Industry Table -->
<div class="card border-0 shadow-sm p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Industry Breakdown ({{ count($records) }})</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Industry Name</th>
                    <th class="text-center">Members</th>
                    <th class="text-center">Active Members</th>
                    <th class="text-center">Circles</th>
                    <th class="text-center">Circle Directors</th>
                    <th class="text-center">Industry Directors</th>
                    <th class="text-center">Business Deals</th>
                    <th class="text-center">Referrals</th>
                    <th class="text-center">Testimonials</th>
                    <th class="text-end">Membership Revenue</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $r)
                    <tr>
                        <td>
                            <div class="fw-bold text-dark">{{ $r['name'] }}</div>
                        </td>
                        <td class="text-center fw-medium">{{ number_format($r['members_count']) }}</td>
                        <td class="text-center">
                            <span class="badge bg-success-subtle text-success py-1 px-2.5 rounded-pill small">
                                {{ number_format($r['active_members_count']) }}
                            </span>
                        </td>
                        <td class="text-center fw-medium">{{ number_format($r['circles_count']) }}</td>
                        <td class="text-center">{{ number_format($r['circle_directors_count']) }}</td>
                        <td class="text-center">{{ number_format($r['industry_directors_count']) }}</td>
                        <td class="text-center text-muted">{{ number_format($r['deals_count']) }}</td>
                        <td class="text-center text-muted">{{ number_format($r['referrals_count']) }}</td>
                        <td class="text-center text-muted">{{ number_format($r['testimonials_count']) }}</td>
                        <td class="text-end fw-semibold text-success">
                            ₹{{ number_format($r['revenue']) }}
                        </td>
                        <td class="text-center">
                            <span class="badge py-1 px-2.5 rounded-pill small badge-{{ strtolower($r['status']) }}">
                                {{ $r['status'] }}
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="{{ route('admin.ded.dashboard.industries.detail', ['id' => $r['id'], 'circle_id' => $filters['circle_id'] ?? 'all']) }}" class="btn btn-sm btn-outline-primary px-3">
                                View Details
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="text-center text-muted py-4">No industry records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
