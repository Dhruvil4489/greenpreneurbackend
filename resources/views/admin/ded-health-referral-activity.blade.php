@extends('admin.layouts.app')

@section('title', 'DED - Referral Activity (30d)')

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
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-1">Ecosystem Health Drilldown</p>
        <h3 class="mb-0 fw-bold text-primary-gradient">Referral Activity (30d)</h3>
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
            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Current Rate</span>
            <h1 class="display-6 mb-0 fw-bold text-warning">{{ $summary['percentage'] }}%</h1>
        </div>
        <div class="col-md-9 ps-md-4">
            <div class="row g-3">
                <div class="col-6 col-sm-4">
                    <span class="text-muted small d-block">District</span>
                    <strong class="text-dark fs-5">{{ $districtName }}</strong>
                </div>
                <div class="col-6 col-sm-4">
                    <span class="text-muted small d-block">Referring Peers</span>
                    <strong class="text-dark fs-5">{{ number_format($summary['numerator']) }}</strong>
                </div>
                <div class="col-6 col-sm-4">
                    <span class="text-muted small d-block">Total Peers</span>
                    <strong class="text-dark fs-5">{{ number_format($summary['denominator']) }}</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Section -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" action="{{ route('admin.ded.dashboard.health.referral-activity') }}" class="row g-3 align-items-end">
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
        <div class="col-md-3">
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
        <div class="col-md-3">
            <label for="dateFrom" class="form-label small text-muted mb-1 fw-bold">Referral Date From</label>
            <input type="date" id="dateFrom" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <label for="dateTo" class="form-label small text-muted mb-1 fw-bold">Referral Date To</label>
            <input type="date" id="dateTo" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.ded.dashboard.health.referral-activity') }}" class="btn btn-outline-secondary px-4">Reset Filters</a>
            <button type="submit" class="btn btn-primary px-4">Apply Filters</button>
        </div>
    </form>
</div>

<!-- Detailed Records List -->
<div class="card border-0 shadow-sm p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Referrals Passed ({{ count($records) }})</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>From (Sender)</th>
                    <th>Sender Info</th>
                    <th>To (Receiver)</th>
                    <th>Receiver Info</th>
                    <th>Referral Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $r)
                    <tr>
                        <td>
                            <div class="fw-bold text-dark">{{ $r['from_user_name'] }}</div>
                            <div class="small text-muted">{{ $r['from_user_company'] }}</div>
                        </td>
                        <td>
                            <div class="small text-muted">
                                <div><i class="bi bi-phone"></i> {{ $r['from_user_phone'] }}</div>
                                <div><i class="bi bi-envelope"></i> {{ $r['from_user_email'] }}</div>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">{{ $r['to_user_name'] }}</div>
                            <div class="small text-muted">{{ $r['to_user_company'] }}</div>
                        </td>
                        <td>
                            <div class="small text-muted">
                                <div><i class="bi bi-phone"></i> {{ $r['to_user_phone'] }}</div>
                                <div><i class="bi bi-envelope"></i> {{ $r['to_user_email'] }}</div>
                            </div>
                        </td>
                        <td class="small text-muted">
                            {{ $r['created_at'] ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('Y-m-d H:i') : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No referral activity records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
