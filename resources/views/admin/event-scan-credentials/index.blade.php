@extends('admin.layouts.app')

@section('title', 'Event Scan Credentials')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Event Scan Credentials</h1>
        <a href="{{ route('admin.event-scan-credentials.create') }}" class="btn btn-primary">Create Credential</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-4">
                <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search name, username, hotel, event">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary">Filter</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Username</th>
                        <th>Hotel</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($credentials as $credential)
                        <tr>
                            <td>{{ $credential->name }}</td>
                            <td>{{ $credential->username }}</td>
                            <td>{{ $credential->hotel_name }}</td>
                            <td>{{ $credential->event?->title ?? $credential->event_name ?? '-' }}</td>
                            <td><span class="badge {{ $credential->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $credential->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ optional($credential->last_login_at)->toDateTimeString() ?? '-' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.event-scan-credentials.edit', $credential->id) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                <form action="{{ route('admin.event-scan-credentials.toggle', $credential->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-warning">{{ $credential->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No scanner credentials found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $credentials->links() }}</div>
</div>
@endsection
