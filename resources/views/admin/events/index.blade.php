@extends('admin.layouts.app')

@section('title', 'Events Management')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Events Management</h1>
        <a href="{{ route('admin.events.create') }}" class="btn btn-primary">Create Event</a>
    </div>

    <form class="card card-body mb-3" method="GET">
        <div class="row g-2">
            <div class="col-md-2"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search title"></div>
            <div class="col-md-2"><select class="form-select" name="event_type"><option value="">All Types</option>@foreach(['circle_meeting','global_event','public_event'] as $type)<option value="{{ $type }}" @selected(request('event_type')===$type)>{{ $type }}</option>@endforeach</select></div>
            <div class="col-md-2"><select class="form-select" name="circle_id"><option value="">All Circles</option>@foreach($circles as $circle)<option value="{{ $circle->id }}" @selected(request('circle_id')===$circle->id)>{{ $circle->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><select class="form-select" name="mode"><option value="">All Modes</option>@foreach(['offline','online','hybrid'] as $mode)<option value="{{ $mode }}" @selected(request('mode')===$mode)>{{ ucfirst($mode) }}</option>@endforeach</select></div>
            <div class="col-md-2"><input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}"></div>
            <div class="col-md-2"><input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}"></div>
            <div class="col-12"><button class="btn btn-outline-primary">Filter</button><a class="btn btn-link" href="{{ route('admin.events.index') }}">Reset</a></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Title</th><th>Type</th><th>Circle</th><th>Mode</th><th>Start date</th><th>Recurrence</th><th>Registered</th><th>Checked-in</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($events as $event)
                    <tr>
                        <td>{{ $event->title }}</td><td>{{ $event->event_type }}</td><td>{{ $event->circle?->name ?? '-' }}</td><td>{{ $event->mode }}</td>
                        <td>{{ optional($event->start_at)->format('d M Y h:i A') }}</td><td>{{ $event->recurrence_type ?? 'none' }}</td>
                        <td>{{ $event->registered_count ?? 0 }}</td><td>{{ $event->checked_in_count ?? 0 }}</td><td>{{ $event->status ?? 'scheduled' }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.events.show', $event->id) }}">View</a> <a class="btn btn-sm btn-outline-success" href="{{ route('admin.events.attendance', $event->id) }}">Attendance</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No events found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $events->links() }}</div>
    </div>
</div>
@endsection
