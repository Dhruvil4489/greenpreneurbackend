@extends('admin.layouts.app')

@section('title', 'Activity Creatives')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Activity Creatives</h1>
    <span class="badge bg-light text-dark border">Total: {{ number_format($items->total()) }}</span>
</div>

<form class="card card-body mb-3" method="GET">
    <div class="row g-2">
        <div class="col-md-3"><input class="form-control" name="q" placeholder="User search" value="{{ $filters['q'] ?? '' }}"></div>
        <div class="col-md-2"><input class="form-control" name="activity_type" placeholder="Activity type" value="{{ $filters['activity_type'] ?? '' }}"></div>
        <div class="col-md-2"><input type="date" class="form-control" name="from_date" value="{{ $filters['from_date'] ?? '' }}"></div>
        <div class="col-md-2"><input type="date" class="form-control" name="to_date" value="{{ $filters['to_date'] ?? '' }}"></div>
        <div class="col-md-2"><input class="form-control" name="status" placeholder="Status" value="{{ $filters['status'] ?? '' }}"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead><tr><th>User</th><th>Activity Type</th><th>Activity ID</th><th>Title</th><th>Creative</th><th>Post ID</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($items as $item)
                <tr>
                    <td>{{ $item->user?->display_name ?? trim(($item->user?->first_name ?? '').' '.($item->user?->last_name ?? '')) ?: ($item->user?->email ?? '—') }}</td>
                    <td>{{ $item->activity_type }}</td>
                    <td><small>{{ $item->activity_id ?? '—' }}</small></td>
                    <td>{{ $item->title ?? '—' }}</td>
                    <td>@if($item->creative_url)<img src="{{ $item->creative_url }}" style="width:80px;height:80px;object-fit:cover;">@endif</td>
                    <td><small>{{ $item->post_id ?? '—' }}</small></td>
                    <td>{{ optional($item->created_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $item->status }}</td>
                    <td>
                        @if($item->creative_url)<a class="btn btn-sm btn-outline-secondary" href="{{ $item->creative_url }}" download target="_blank">Download</a>@endif
                        @if($item->post_id)<a class="btn btn-sm btn-outline-primary" href="{{ url('/admin/posts/'.$item->post_id) }}" target="_blank">View Post</a>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No records found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
