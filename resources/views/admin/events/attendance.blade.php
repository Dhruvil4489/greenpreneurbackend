@extends('admin.layouts.app')

@section('title', 'Attendance - '.$event->title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">Attendance: {{ $event->title }}</h1><a href="{{ route('admin.events.show', $event->id) }}" class="btn btn-outline-secondary">Back</a></div>
    <div class="row g-3 mb-3">
        @foreach($report['summary'] as $label => $value)
            <div class="col-md-2"><div class="card card-body text-center"><div class="fw-bold">{{ $value }}</div><small class="text-muted">{{ str_replace('_',' ', $label) }}</small></div></div>
        @endforeach
    </div>
    <form method="GET" class="card card-body mb-3"><div class="row g-2"><div class="col-md-3"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search name/email/phone"></div><div class="col-md-2"><select class="form-select" name="attendee_type"><option value="">All</option><option value="member" @selected(request('attendee_type')==='member')>Member</option><option value="visitor" @selected(request('attendee_type')==='visitor')>Visitor</option></select></div><div class="col-md-2"><select class="form-select" name="checkin_status"><option value="">Any check-in</option><option value="pending">Pending</option><option value="checked_in">Checked In</option></select></div><div class="col-md-2"><button class="btn btn-outline-primary">Filter</button></div></div></form>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Name</th><th>Type</th><th>Phone/Email</th><th>Company/City</th><th>Status</th><th>Payment</th><th>Razorpay</th><th>Invoice</th><th>Check-in</th><th>Registered</th><th>Checked in</th><th>QR</th></tr></thead><tbody>
        @forelse($report['items'] as $item)
            <tr>
                <td>{{ $item['user']['name'] ?? $item['visitor']['name'] ?? '-' }}</td><td>{{ $item['attendee_type'] }}</td>
                <td>{{ $item['user']['phone'] ?? $item['visitor']['phone'] ?? '-' }}<br><small>{{ $item['user']['email'] ?? $item['visitor']['email'] ?? '' }}</small></td>
                <td>{{ $item['user']['company_name'] ?? $item['visitor']['company'] ?? '-' }}<br><small>{{ $item['user']['city'] ?? $item['visitor']['city'] ?? '' }}</small></td>
                <td>{{ $item['status'] }}</td><td>{{ $item['payment_status'] ?? '-' }}</td><td>{{ $item['razorpay_payment_id'] ?? '-' }}</td><td>@if(!empty($item['invoice_pdf_url']) || !empty($item['invoice_url']))<a href="{{ $item['invoice_pdf_url'] ?? $item['invoice_url'] }}" target="_blank">{{ $item['zoho_invoice_number'] ?? 'Invoice' }}</a>@else {{ $item['invoice_sync_status'] ?? '-' }} @endif</td><td>{{ $item['checkin_status'] }}</td><td>{{ $item['registered_at'] }}</td><td>{{ $item['checked_in_at'] ?? '-' }}</td>
                <td>@if($item['qr_code_url'])<a href="{{ $item['qr_code_url'] }}" target="_blank">Open QR</a>@else - @endif</td>
            </tr>
        @empty
            <tr><td colspan="12" class="text-center text-muted py-4">No registrations found.</td></tr>
        @endforelse
    </tbody></table></div></div>

    <div class="card mt-3">
        <div class="card-header fw-semibold">Scan History</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Scanned User</th><th>Scanner Person</th><th>Hotel</th><th>Status</th><th>Scanned Time</th><th>Device Info</th></tr></thead>
                <tbody>
                    @forelse(($scanLogs ?? collect()) as $log)
                        <tr>
                            <td>{{ $log->user?->display_name ?? $log->user?->email ?? data_get($log->meta, 'registration_id', '-') }}</td>
                            <td>{{ $log->scanner?->name ?? '-' }}</td>
                            <td>{{ $log->scanner?->hotel_name ?? '-' }}</td>
                            <td>{{ $log->scan_status }}</td>
                            <td>{{ optional($log->scanned_at)->toDateTimeString() ?? '-' }}</td>
                            <td><small>{{ $log->device_info ? json_encode($log->device_info) : '-' }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No scan history found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
