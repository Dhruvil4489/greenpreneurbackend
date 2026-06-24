@extends('admin.layouts.app')

@section('title', 'Inactive Registrations')

@section('content')
    @php
        $displayName = function (?string $display, ?string $first, ?string $last): string {
            if ($display) {
                return $display;
            }
            $computed = trim(($first ?? '') . ' ' . ($last ?? ''));
            return $computed !== '' ? $computed : '—';
        };

        $formatDateTime = function ($value): string {
            return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '—';
        };
    @endphp

    <form id="pendingRegistrationsFiltersForm" method="GET" action="{{ route('admin.pending-registrations.index') }}"></form>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Inactive Registrations</h1>
        <span class="badge bg-light text-dark border">Total: {{ number_format($registrations->total()) }}</span>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-muted">Search Name, Email, Mobile, or City</label>
                    <input type="text" name="search" form="pendingRegistrationsFiltersForm" value="{{ $filters['search'] }}" class="form-control" placeholder="Search name, email, phone, city...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" form="pendingRegistrationsFiltersForm" class="form-select">
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                        <option value="rejected" @selected($filters['status'] === 'rejected')>Rejected</option>
                        <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Registered Date</label>
                    <input type="date" name="date" form="pendingRegistrationsFiltersForm" value="{{ $filters['date'] }}" class="form-control">
                </div>
                <div class="col-md-2 d-flex flex-column gap-2">
                    <button type="submit" form="pendingRegistrationsFiltersForm" class="btn btn-primary">Apply Filters</button>
                    <a href="{{ route('admin.pending-registrations.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Registered At</th>
                        <th>User Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>City</th>
                        <th>Company & Designation</th>
                        <th>Status</th>
                        <th class="text-end" style="min-width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registrations as $registration)
                        @php
                            $fullName = $displayName($registration->display_name ?? null, $registration->first_name ?? null, $registration->last_name ?? null);
                            $company = $registration->company_name ?? '—';
                            $designation = $registration->designation ?? '—';
                            $city = $registration->city_of_residence ?: ($registration->city ?: '—');
                        @endphp
                        <tr>
                            <td>{{ $formatDateTime($registration->created_at ?? null) }}</td>
                            <td>
                                <div class="fw-semibold">{{ $fullName }}</div>
                            </td>
                            <td>{{ $registration->email }}</td>
                            <td>{{ $registration->phone ?? '—' }}</td>
                            <td>{{ $city }}</td>
                            <td>
                                <div>{{ $company }}</div>
                                <div class="text-muted small">{{ $designation }}</div>
                            </td>
                            <td>
                                @if ($registration->status === 'inactive')
                                    <span class="badge bg-secondary">Inactive</span>
                                @elseif ($registration->status === 'rejected')
                                    <span class="badge bg-danger">Rejected</span>
                                @elseif ($registration->status === 'active')
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($registration->status) }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($registration->status === 'inactive')
                                    <form method="POST" action="{{ route('admin.pending-registrations.approve', $registration->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Are you sure you want to approve this registration request? This will activate the account and send an approval email.')">
                                            <i class="bi bi-check-circle me-1"></i>Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.pending-registrations.reject', $registration->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to reject this registration request? This will block login and send a rejection email.')">
                                            <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
                                    </form>
                                @elseif ($registration->status === 'rejected')
                                    <form method="POST" action="{{ route('admin.pending-registrations.approve', $registration->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Approve this previously rejected user?')">
                                            Approve
                                        </button>
                                    </form>
                                @elseif ($registration->status === 'active')
                                    <form method="POST" action="{{ route('admin.pending-registrations.reject', $registration->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this active user?')">
                                            Reject
                                        </button>
                                    </form>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No inactive or rejected registration requests found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $registrations->links() }}
    </div>
@endsection
