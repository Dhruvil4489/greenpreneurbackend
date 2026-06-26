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
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#regDetailsModal-{{ $registration->id }}">
                                    <i class="bi bi-eye me-1"></i>Details
                                </button>
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

                                <!-- Registration Details Modal -->
                                <div class="modal fade" id="regDetailsModal-{{ $registration->id }}" tabindex="-1" aria-labelledby="regDetailsModalLabel-{{ $registration->id }}" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="regDetailsModalLabel-{{ $registration->id }}">Registration Details: {{ $fullName }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <div class="row g-3">
                                                    <!-- Basic Information -->
                                                    <div class="col-md-6">
                                                        <strong>First Name:</strong> <span class="text-muted">{{ $registration->first_name }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Last Name:</strong> <span class="text-muted">{{ $registration->last_name ?? '—' }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Email:</strong> <span class="text-muted">{{ $registration->email }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Mobile:</strong> <span class="text-muted">{{ $registration->phone ?? '—' }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Company Name:</strong> <span class="text-muted">{{ $registration->company_name ?? '—' }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Designation:</strong> <span class="text-muted">{{ $registration->designation ?? '—' }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>City:</strong> <span class="text-muted">{{ $city }}</span>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Website:</strong> 
                                                        @if($registration->website)
                                                            <a href="{{ $registration->website }}" target="_blank">{{ $registration->website }}</a>
                                                        @else
                                                            <span class="text-muted">—</span>
                                                        @endif
                                                    </div>
                                                    <div class="col-md-12">
                                                        <strong>List in Community Directory?</strong> <span class="text-muted">{{ $registration->community_directory_listing ?? 'No' }}</span>
                                                    </div>

                                                    <div class="col-12">
                                                        <hr class="my-2">
                                                    </div>

                                                    <!-- Sustainability contribution & areas -->
                                                    <div class="col-12">
                                                        <strong>How does your business contribute to sustainability?</strong>
                                                        <div class="p-2 bg-light border rounded mt-1 text-muted" style="white-space: pre-wrap;">{{ $registration->sustainability_contribution ?: 'No contribution specified.' }}</div>
                                                    </div>

                                                    <div class="col-12">
                                                        <strong>Which sustainability areas do you focus on?</strong>
                                                        <div class="mt-1">
                                                            @if(is_array($registration->sustainability_areas) && count($registration->sustainability_areas) > 0)
                                                                @foreach($registration->sustainability_areas as $area)
                                                                    <span class="badge bg-success me-1 mb-1">{{ $area }}</span>
                                                                @endforeach
                                                            @else
                                                                <span class="text-muted">None selected</span>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="col-12">
                                                        <strong>What are you looking for through Greenpreneur?</strong>
                                                        <div class="mt-1">
                                                            @if(is_array($registration->greenpreneur_goals) && count($registration->greenpreneur_goals) > 0)
                                                                @foreach($registration->greenpreneur_goals as $goal)
                                                                    <span class="badge bg-primary me-1 mb-1">{{ $goal }}</span>
                                                                @endforeach
                                                            @else
                                                                <span class="text-muted">None selected</span>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="col-12">
                                                        <strong>Are you interested in:</strong>
                                                        <div class="mt-1">
                                                            @if(is_array($registration->interests) && count($registration->interests) > 0)
                                                                @foreach($registration->interests as $interest)
                                                                    <span class="badge bg-info text-dark me-1 mb-1">{{ $interest }}</span>
                                                                @endforeach
                                                            @else
                                                                <span class="text-muted">None selected</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                @if ($registration->status === 'inactive')
                                                    <form method="POST" action="{{ route('admin.pending-registrations.approve', $registration->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to approve this registration request?')">
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('admin.pending-registrations.reject', $registration->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to reject this registration request?')">
                                                            Reject
                                                        </button>
                                                    </form>
                                                @endif
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
