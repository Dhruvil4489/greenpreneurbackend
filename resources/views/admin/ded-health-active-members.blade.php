@extends('admin.layouts.app')

@section('title', 'DED - Active Members')

@section('content')
<style>
    .kpi-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.06);
    }
    .text-primary-gradient {
        background: linear-gradient(45deg, #0d6efd, #0dcaf0);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .badge-status-active { background-color: #d1e7dd; color: #0f5132; }
    .badge-status-inactive { background-color: #f8d7da; color: #842029; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-1">Ecosystem Health Drilldown</p>
        <h3 class="mb-0 fw-bold text-primary-gradient">Active Members</h3>
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

<!-- Executive Summary Section -->
<div class="card p-4 border-0 shadow-sm kpi-card mb-4">
    <div class="row align-items-center">
        <div class="col-md-3 border-end border-light text-center">
            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Active Percentage</span>
            <h1 class="display-6 mb-0 fw-bold text-success">{{ $summary['percentage'] }}%</h1>
        </div>
        <div class="col-md-9 ps-md-4">
            <div class="row g-3">
                <div class="col-6 col-sm-3">
                    <span class="text-muted small d-block">District</span>
                    <strong class="text-dark fs-5">{{ $districtName }}</strong>
                </div>
                <div class="col-6 col-sm-3">
                    <span class="text-muted small d-block">Active Members</span>
                    <strong class="text-dark fs-5 text-success">{{ number_format($summary['active_count']) }}</strong>
                </div>
                <div class="col-6 col-sm-3">
                    <span class="text-muted small d-block">Inactive Members</span>
                    <strong class="text-dark fs-5 text-danger">{{ number_format($summary['inactive_count']) }}</strong>
                </div>
                <div class="col-6 col-sm-3">
                    <span class="text-muted small d-block">Total Members</span>
                    <strong class="text-dark fs-5">{{ number_format($summary['denominator']) }}</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Section -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" action="{{ route('admin.ded.dashboard.health.active-members') }}" class="row g-3 align-items-end">
        <div class="col-md-2">
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
            <label for="industryFilter" class="form-label small text-muted mb-1 fw-bold">Industry</label>
            <select id="industryFilter" name="industry_id" class="form-select">
                <option value="all" @selected(($filters['industry_id'] ?? '') === '' || ($filters['industry_id'] ?? '') === 'all')>All Industries</option>
                @foreach ($industries as $ind)
                    <option value="{{ $ind->id }}" @selected(($filters['industry_id'] ?? '') === $ind->id)>
                        {{ $ind->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="statusFilter" class="form-label small text-muted mb-1 fw-bold">Status</label>
            <select id="statusFilter" name="status" class="form-select">
                <option value="all" @selected(($filters['status'] ?? '') === '' || ($filters['status'] ?? '') === 'all')>All Statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="col-md-3">
            <label for="dateFrom" class="form-label small text-muted mb-1 fw-bold">Date From</label>
            <input type="date" id="dateFrom" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <label for="dateTo" class="form-label small text-muted mb-1 fw-bold">Date To</label>
            <input type="date" id="dateTo" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.ded.dashboard.health.active-members') }}" class="btn btn-outline-secondary px-4">Reset Filters</a>
            <button type="submit" class="btn btn-primary px-4">Apply Filters</button>
        </div>
    </form>
</div>

<!-- Detailed Records List -->
<div class="card border-0 shadow-sm p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Records ({{ count($records) }})</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Contact Info</th>
                    <th>Company / Industry</th>
                    <th>Created At</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $r)
                    <tr>
                        <td>
                            <div class="fw-bold text-dark">{{ $r['name'] }}</div>
                        </td>
                        <td>
                            <div class="small">
                                <div><i class="bi bi-phone"></i> {{ $r['phone'] }}</div>
                                <div><i class="bi bi-envelope"></i> {{ $r['email'] }}</div>
                            </div>
                        </td>
                        <td>
                            <div>{{ $r['company'] }}</div>
                            <span class="badge bg-light text-secondary border small mt-1">{{ $r['industry'] }}</span>
                        </td>
                        <td class="small text-muted">
                            {{ $r['created_at'] ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('Y-m-d H:i') : '—' }}
                        </td>
                        <td class="small text-muted">
                            {{ $r['last_login_at'] ? \Illuminate\Support\Carbon::parse($r['last_login_at'])->format('Y-m-d H:i') : 'Never' }}
                        </td>
                        <td>
                            <span class="badge py-1 px-2.5 rounded-pill small badge-status-{{ $r['status'] }}">
                                {{ ucfirst($r['status']) }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('admin.users.show', $r['id']) }}" class="btn btn-sm btn-outline-secondary px-3" target="_blank" rel="noopener">
                                View Profile
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No member records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
