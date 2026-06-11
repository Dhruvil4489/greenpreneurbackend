@extends('admin.layouts.app')

@section('title', 'Ads')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Ads</h1>
    <a href="{{ route('admin.ads.create') }}" class="btn btn-primary btn-sm">Add Ad</a>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card shadow-sm">
    <div class="card-body border-bottom">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-sm-4">
                <input type="text" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search ad title">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">Search</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Image</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th style="width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ads as $ad)
                    <tr>
                        <td>{{ $ad->id }}</td>
                        <td>
                            @if($ad->image_url)
                                <img src="{{ $ad->image_url }}" alt="Ad image" class="img-thumbnail" style="height:52px; width:auto;">
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $ad->title }}</td>
                        <td>
                            <span class="badge {{ $ad->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $ad->is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td>{{ $ad->starts_at ? $ad->starts_at->timezone('Asia/Kolkata')->format('Y-m-d H:i') : '—' }}</td>
                        <td>{{ $ad->ends_at ? $ad->ends_at->timezone('Asia/Kolkata')->format('Y-m-d H:i') : '—' }}</td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="{{ route('admin.ads.edit', $ad) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                <form method="POST" action="{{ route('admin.ads.toggle-status', $ad) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-warning">{{ $ad->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.ads.destroy', $ad) }}" onsubmit="return confirm('Delete this ad?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">No ads found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        {{ $ads->links() }}
    </div>
</div>
@endsection
