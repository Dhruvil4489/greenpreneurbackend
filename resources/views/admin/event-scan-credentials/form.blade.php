@extends('admin.layouts.app')

@section('title', $credential->exists ? 'Edit Scan Credential' : 'Create Scan Credential')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ $credential->exists ? 'Edit Scan Credential' : 'Create Scan Credential' }}</h1>
        <a href="{{ route('admin.event-scan-credentials.index') }}" class="btn btn-outline-secondary">Back</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <form method="POST" action="{{ $credential->exists ? route('admin.event-scan-credentials.update', $credential->id) : route('admin.event-scan-credentials.store') }}" class="card card-body">
                @csrf
                @if($credential->exists) @method('PUT') @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Person name</label>
                        <input class="form-control" name="name" value="{{ old('name', $credential->name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username / login id</label>
                        <input class="form-control" name="username" value="{{ old('username', $credential->username) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password {{ $credential->exists ? '(leave blank to keep current)' : '' }}</label>
                        <input type="password" class="form-control" name="password" {{ $credential->exists ? '' : 'required' }}>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm password</label>
                        <input type="password" class="form-control" name="password_confirmation" {{ $credential->exists ? '' : 'required' }}>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hotel name</label>
                        <input class="form-control" name="hotel_name" value="{{ old('hotel_name', $credential->hotel_name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Assigned event</label>
                        <select class="form-select" name="event_id" required>
                            <option value="">Select event</option>
                            @foreach($events as $event)
                                <option value="{{ $event->id }}" @selected(old('event_id', $credential->event_id) === $event->id)>
                                    {{ $event->title }}{{ $event->start_at ? ' — '.$event->start_at->format('Y-m-d') : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" @checked(old('is_active', $credential->is_active))>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button class="btn btn-primary">{{ $credential->exists ? 'Update Credential' : 'Create Credential' }}</button>
                </div>
            </form>
        </div>

        @if($credential->exists)
            <div class="col-lg-4">
                <form method="POST" action="{{ route('admin.event-scan-credentials.reset-password', $credential->id) }}" class="card card-body">
                    @csrf
                    <h2 class="h6">Reset Password</h2>
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" class="form-control" name="password_confirmation" required>
                    </div>
                    <button class="btn btn-outline-warning">Reset Password</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
