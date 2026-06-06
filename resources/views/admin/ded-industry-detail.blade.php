@extends('admin.layouts.app')

@section('title', 'DED - Industry Detail')

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
    .badge-active { background-color: #d1e7dd; color: #0f5132; }
    .badge-inactive { background-color: #f8d7da; color: #842029; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-1">Industry Details & Activities</p>
        <h3 class="mb-0 fw-bold text-primary-gradient">{{ $summary['name'] }}</h3>
    </div>
    <div class="d-flex gap-2">
        @if ($districtName)
            <span class="fs-6 py-2 px-3 badge bg-primary text-white border border-primary-subtle align-self-center">District: {{ $districtName }}</span>
        @endif
        <a href="{{ route('admin.ded.dashboard.industries', ['circle_id' => $filters['circle_id'] ?? 'all']) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Overview
        </a>
    </div>
</div>

<!-- Industry Summary Cards -->
<div class="card p-4 border-0 shadow-sm kpi-card mb-4">
    <h5 class="fw-bold mb-3 text-dark border-bottom pb-2">Industry Summary</h5>
    <div class="row g-3">
        <div class="col-6 col-md-2 border-end border-light">
            <span class="text-muted small d-block">Total Members</span>
            <strong class="text-dark fs-4">{{ number_format($summary['total_members']) }}</strong>
        </div>
        <div class="col-6 col-md-2 border-end border-light">
            <span class="text-muted small d-block">Active Members</span>
            <strong class="text-success fs-4">{{ number_format($summary['active_members']) }}</strong>
        </div>
        <div class="col-6 col-md-2 border-end border-light">
            <span class="text-muted small d-block">Inactive Members</span>
            <strong class="text-danger fs-4">{{ number_format($summary['inactive_members']) }}</strong>
        </div>
        <div class="col-6 col-md-2 border-end border-light">
            <span class="text-muted small d-block">Active Percentage</span>
            <strong class="text-success fs-4">{{ $summary['active_percentage'] }}%</strong>
        </div>
        <div class="col-6 col-md-2 border-end border-light">
            <span class="text-muted small d-block">Total Circles</span>
            <strong class="text-primary fs-4">{{ number_format($summary['total_circles']) }}</strong>
        </div>
        <div class="col-6 col-md-2">
            <span class="text-muted small d-block">Membership Revenue</span>
            <strong class="text-success fs-4">₹{{ number_format($summary['revenue']) }}</strong>
        </div>
    </div>
    <div class="row g-3 mt-3 pt-3 border-top border-light">
        <div class="col-6 col-sm-4 col-md-2.4 border-end border-light">
            <span class="text-muted small d-block">Circle Directors</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['circle_directors']) }}</strong>
        </div>
        <div class="col-6 col-sm-4 col-md-2.4 border-end border-light">
            <span class="text-muted small d-block">Industry Directors</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['industry_directors']) }}</strong>
        </div>
        <div class="col-6 col-sm-4 col-md-2.4 border-end border-light">
            <span class="text-muted small d-block">Business Deals</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['deals']) }}</strong>
        </div>
        <div class="col-6 col-sm-4 col-md-2.4 border-end border-light">
            <span class="text-muted small d-block">Referrals</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['referrals']) }}</strong>
        </div>
        <div class="col-6 col-sm-4 col-md-2.4 border-end border-light">
            <span class="text-muted small d-block">Testimonials</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['testimonials']) }}</strong>
        </div>
        <div class="col-6 col-sm-4 col-md-2.4">
            <span class="text-muted small d-block">P2P Meetings</span>
            <strong class="text-dark fw-bold">{{ number_format($summary['meetings']) }}</strong>
        </div>
    </div>
</div>

<!-- Filters Section -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" action="{{ route('admin.ded.dashboard.industries.detail', $industryId) }}" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label for="circleFilter" class="form-label small text-muted mb-1 fw-bold">Circle Scope</label>
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
            <label for="dateFrom" class="form-label small text-muted mb-1 fw-bold">Date From</label>
            <input type="date" id="dateFrom" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <label for="dateTo" class="form-label small text-muted mb-1 fw-bold">Date To</label>
            <input type="date" id="dateTo" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <a href="{{ route('admin.ded.dashboard.industries.detail', $industryId) }}" class="btn btn-outline-secondary w-50">Reset</a>
            <button type="submit" class="btn btn-primary w-50">Apply</button>
        </div>
    </form>
</div>

<!-- Main Sections Tabs -->
<div class="card border-0 shadow-sm p-4 mb-4">
    <ul class="nav nav-tabs mb-4" id="detailTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="members-tab" data-bs-toggle="tab" data-bs-target="#membersSection" type="button" role="tab">
                Industry Members ({{ count($members) }})
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="circles-tab" data-bs-toggle="tab" data-bs-target="#circlesSection" type="button" role="tab">
                Industry Circles ({{ count($circles) }})
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="activities-tab" data-bs-toggle="tab" data-bs-target="#activitiesSection" type="button" role="tab">
                Industry Activities
            </button>
        </li>
    </ul>

    <div class="tab-content" id="detailTabsContent">
        <!-- Members Section -->
        <div class="tab-pane fade show active" id="membersSection" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Circle</th>
                            <th class="text-center">Status</th>
                            <th>Joined Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($members as $m)
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">{{ $m['name'] }}</div>
                                </td>
                                <td>{{ $m['company'] }}</td>
                                <td>
                                    <span class="badge bg-light text-secondary border small">{{ $m['role'] }}</span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-primary">{{ $m['circle'] }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge py-1 px-2.5 rounded-pill small badge-{{ strtolower($m['status']) }}">
                                        {{ ucfirst($m['status']) }}
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    {{ $m['joined_date'] ? \Illuminate\Support\Carbon::parse($m['joined_date'])->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('admin.users.show', $m['id']) }}" class="btn btn-sm btn-outline-secondary px-3" target="_blank">
                                        View Profile
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No members found in this industry.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Circles Section -->
        <div class="tab-pane fade" id="circlesSection" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Circle Name</th>
                            <th>Founder</th>
                            <th>Director</th>
                            <th class="text-center">Members</th>
                            <th class="text-end">Membership Revenue</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($circles as $c)
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">{{ $c['name'] }}</div>
                                </td>
                                <td>{{ $c['founder'] }}</td>
                                <td>{{ $c['director'] }}</td>
                                <td class="text-center fw-medium">{{ number_format($c['members_count']) }}</td>
                                <td class="text-end fw-semibold text-success">
                                    ₹{{ number_format($c['revenue']) }}
                                </td>
                                <td class="text-center">
                                    <span class="badge py-1 px-2.5 rounded-pill small badge-{{ strtolower($c['status']) }}">
                                        {{ ucfirst($c['status']) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No circles found in this industry.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Activities Section -->
        <div class="tab-pane fade" id="activitiesSection" role="tabpanel">
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="card p-3 border border-light text-center bg-light">
                        <i class="bi bi-briefcase-fill text-warning fs-1 mb-2"></i>
                        <h5 class="fw-bold text-dark mb-1">{{ number_format($summary['deals']) }}</h5>
                        <span class="text-muted small">Business Deals Closed</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 border border-light text-center bg-light">
                        <i class="bi bi-arrow-left-right text-primary fs-1 mb-2"></i>
                        <h5 class="fw-bold text-dark mb-1">{{ number_format($summary['referrals']) }}</h5>
                        <span class="text-muted small">Referrals Passed</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 border border-light text-center bg-light">
                        <i class="bi bi-chat-quote-fill text-info fs-1 mb-2"></i>
                        <h5 class="fw-bold text-dark mb-1">{{ number_format($summary['testimonials']) }}</h5>
                        <span class="text-muted small">Testimonials Exchanged</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 border border-light text-center bg-light">
                        <i class="bi bi-people-fill text-success fs-1 mb-2"></i>
                        <h5 class="fw-bold text-dark mb-1">{{ number_format($summary['meetings']) }}</h5>
                        <span class="text-muted small">P2P Meetings Done</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
