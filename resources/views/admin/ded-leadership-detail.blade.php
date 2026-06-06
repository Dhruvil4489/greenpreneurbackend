@extends('admin.layouts.app')

@section('title', 'DED - ' . $roleTitle)

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
        <p class="text-muted mb-1">Ecosystem Leadership Drilldown</p>
        <h3 class="mb-0 fw-bold text-primary-gradient">{{ $roleTitle }}</h3>
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

<!-- Executive Summary Section (Section 7) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2-4">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Count</span>
            <h3 class="my-2 fw-bold text-dark">{{ number_format($summary['total_count'] ?? 0) }}</h3>
            <span class="text-muted small">leaders active</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2-4">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Revenue Contribution</span>
            <h3 class="my-2 fw-bold text-success">₹{{ number_format($summary['revenue_contribution'] ?? 0) }}</h3>
            <span class="text-muted small">active subscription value</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2-4">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Members Managed</span>
            <h3 class="my-2 fw-bold text-info">{{ number_format($summary['total_members_managed'] ?? 0) }}</h3>
            <span class="text-muted small">peers in covered circles</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2-4">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">Total Circles Covered</span>
            <h3 class="my-2 fw-bold text-primary">{{ number_format($summary['total_circles_covered'] ?? 0) }}</h3>
            <span class="text-muted small">distinct circles</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2-4">
        <div class="card p-3 border-0 shadow-sm kpi-card text-center h-100">
            <span class="text-muted small fw-bold">District Coverage</span>
            <h3 class="my-2 fw-bold text-warning">{{ $summary['district_coverage_pct'] ?? 0 }}%</h3>
            <span class="text-muted small">of total district circles</span>
        </div>
    </div>
</div>

<style>
    @media (min-width: 992px) {
        .col-lg-2-4 {
            flex: 0 0 20%;
            max-width: 20%;
        }
    }
</style>

<!-- Filters Section (Section 8) -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" action="{{ route('admin.ded.dashboard.leadership', ['role' => $role]) }}" class="row g-3 align-items-end">
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
        <div class="col-md-2">
            <label for="statusFilter" class="form-label small text-muted mb-1 fw-bold">Status</label>
            <select id="statusFilter" name="status" class="form-select">
                <option value="all" @selected(($filters['status'] ?? '') === '' || ($filters['status'] ?? '') === 'all')>All Status</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <label for="dateFrom" class="form-label small text-muted mb-1 fw-bold">Joined From</label>
            <input type="date" id="dateFrom" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label for="dateTo" class="form-label small text-muted mb-1 fw-bold">Joined To</label>
            <input type="date" id="dateTo" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-12 d-flex justify-content-end gap-2 mt-3">
            <a href="{{ route('admin.ded.dashboard.leadership', ['role' => $role]) }}" class="btn btn-outline-secondary px-4">Reset Filters</a>
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
                    @if (in_array($role, ['industry_director', 'founder', 'director']))
                        <th>Covered Circles</th>
                        <th>Members Managed</th>
                        <th>Revenue Generated</th>
                    @else
                        <th>Circle</th>
                        @if ($role === 'member')
                            <th>Revenue Contribution</th>
                        @else
                            <th>Circle Members</th>
                            <th>Circle Revenue</th>
                        @endif
                    @endif
                    <th>Activity Score</th>
                    <th>Joined Date</th>
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
                        @if (in_array($role, ['industry_director', 'founder', 'director']))
                            <td>
                                <span class="small text-muted" style="max-width: 250px; display: inline-block;">{{ $r['circles'] }}</span>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info fw-bold fs-6">{{ $r['members_count'] }}</span>
                            </td>
                            <td class="fw-bold text-success">
                                ₹{{ number_format($r['revenue']) }}
                            </td>
                        @else
                            <td>
                                <span class="fw-semibold text-primary">{{ $r['circle_name'] }}</span>
                            </td>
                            @if ($role === 'member')
                                <td class="fw-bold text-success">
                                    ₹{{ number_format($r['revenue']) }}
                                </td>
                            @else
                                <td>
                                    <span class="badge bg-info-subtle text-info fw-bold fs-6">{{ $r['members_count'] }}</span>
                                </td>
                                <td class="fw-bold text-success">
                                    ₹{{ number_format($r['revenue']) }}
                                </td>
                            @endif
                        @endif
                        <td>
                            <div class="d-flex flex-column gap-1">
                                <div class="fw-bold fs-6 text-primary">Score: {{ $r['activity']['score'] ?? 0 }}</div>
                                <div class="small text-muted" style="font-size: 0.75rem;">
                                    M: {{ $r['activity']['meetings'] ?? 0 }} | D: {{ $r['activity']['deals'] ?? 0 }} | R: {{ $r['activity']['referrals'] ?? 0 }}
                                </div>
                            </div>
                        </td>
                        <td class="small text-muted">
                            {{ $r['created_at'] ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('Y-m-d') : '—' }}
                        </td>
                        <td>
                            <span class="badge py-1 px-2.5 rounded-pill small badge-status-{{ $r['status'] }}">
                                {{ ucfirst($r['status']) }}
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                @if(\App\Support\AdminAccess::canEditUsers(Auth::guard('admin')->user()))
                                    <a href="{{ route('admin.users.edit', $r['id']) }}" class="btn btn-outline-secondary px-3" target="_blank" rel="noopener">
                                        Edit
                                    </a>
                                @else
                                    <a href="{{ route('admin.users.show', $r['id']) }}" class="btn btn-outline-secondary px-3" target="_blank" rel="noopener">
                                        View Profile
                                    </a>
                                @endif
                                <button class="btn btn-outline-primary px-3" type="button" data-bs-toggle="collapse" data-bs-target="#details-{{ $r['id'] }}" aria-expanded="false" aria-controls="details-{{ $r['id'] }}">
                                    Details
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr class="collapse-row">
                        <td colspan="10" class="p-0 border-0">
                            <div class="collapse" id="details-{{ $r['id'] }}">
                                <div class="p-3 bg-light border-top">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <table class="table table-sm mb-0 bg-transparent">
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Name</th>
                                                    <td class="border-0 fw-semibold text-dark">{{ $r['name'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Company</th>
                                                    <td class="border-0 text-dark">{{ $r['company'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Email</th>
                                                    <td class="border-0 text-dark">{{ $r['email'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Phone</th>
                                                    <td class="border-0 text-dark">{{ $r['phone'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">District</th>
                                                    <td class="border-0 text-dark">{{ $districtName }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Circle Memberships</th>
                                                    <td class="border-0 text-dark">{{ $r['circle_memberships_list'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Leadership Roles</th>
                                                    <td class="border-0 text-dark">{{ $r['leadership_roles_list'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Coins</th>
                                                    <td class="border-0 fw-bold text-warning">{{ number_format($r['coins_balance']) }}</td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <table class="table table-sm mb-0 bg-transparent">
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Referrals</th>
                                                    <td class="border-0 text-dark">{{ $r['activity']['referrals'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Requirements</th>
                                                    <td class="border-0 text-dark">{{ $r['activity']['requirements'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Testimonials</th>
                                                    <td class="border-0 text-dark">{{ $r['activity']['testimonials'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Business Deals</th>
                                                    <td class="border-0 text-dark">{{ $r['activity']['deals'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">P2P Meetings</th>
                                                    <td class="border-0 text-dark">{{ $r['activity']['meetings'] }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Revenue Contribution</th>
                                                    <td class="border-0 fw-bold text-success">₹{{ number_format($r['revenue']) }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Join Date</th>
                                                    <td class="border-0 text-dark">{{ $r['created_at'] ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('Y-m-d') : '—' }}</td>
                                                </tr>
                                                <tr>
                                                    <th class="w-40 text-muted border-0 small">Status</th>
                                                    <td class="border-0">
                                                        <span class="badge py-1 px-2.5 rounded-pill small badge-status-{{ $r['status'] }}">
                                                            {{ ucfirst($r['status']) }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No leadership records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
