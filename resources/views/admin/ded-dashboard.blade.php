@extends('admin.layouts.app')

@section('title', 'DED Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-1">District Executive Director</p>
        <h5 class="mb-0">DED Dashboard</h5>
    </div>
    @if ($districtName)
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">District: {{ $districtName }}</span>
    @endif
</div>

@if (! $districtName)
    <div class="alert alert-warning">No district assigned. Please contact Global Admin.</div>
@else
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="p-3 rounded border bg-white h-100">
                <p class="text-muted mb-1">District Peers</p>
                <h4 class="mb-0">{{ number_format($stats['total_users'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="p-3 rounded border bg-white h-100">
                <p class="text-muted mb-1">Active District Circles</p>
                <h4 class="mb-0">{{ number_format($stats['active_circles'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="p-3 rounded border bg-white h-100">
                <p class="text-muted mb-1">New Signups Today</p>
                <h4 class="mb-0">{{ number_format($stats['new_signups'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="p-3 rounded border bg-white h-100">
                <p class="text-muted mb-1">Activities Today</p>
                <h4 class="mb-0">{{ number_format($stats['activities_today'] ?? 0) }}</h4>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card p-4 h-100">
                <h6 class="mb-3">District Pending & Reports</h6>
                <div class="list-group list-group-flush">
                    @foreach ($pendingItems as $item)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>{{ $item['title'] }}</span>
                            <span class="badge bg-primary">{{ $item['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-7">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Recent District Peers</h6>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.index') }}">View Peers</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>City</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentPeers as $peer)
                                <tr>
                                    <td>{{ $peer->display_name ?: trim(($peer->first_name ?? '') . ' ' . ($peer->last_name ?? '')) ?: '—' }}</td>
                                    <td>{{ $peer->email ?? '—' }}</td>
                                    <td>{{ $peer->city?->name ?? $peer->city ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">No peers found for this district.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
