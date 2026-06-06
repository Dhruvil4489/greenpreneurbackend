    @extends('admin.layouts.app')

    @section('title', 'Circle Joining Request Detail')

    @php
        $statusLabels = [
            'pending_cd_approval' => 'Pending for CD Approval',
            'pending_id_approval' => 'Pending for ID Approval',
            'pending_circle_fee' => 'Pending for Circle Fee',
            'circle_member' => 'Paid',
            'paid' => 'Paid',
            'rejected_by_cd' => 'Rejected by CD',
            'rejected_by_id' => 'Rejected by ID',
            'cancelled' => 'Cancelled',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    @endphp

    @section('content')
    <div class="container-fluid">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <h5>Peer: {{ $record->user?->adminDisplayName() }}</h5>
                <div>
                    @if($canApproveCd)
                        <form method="POST" action="{{ route('admin.circle-joining-requests.approve-cd', $record->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                        <form method="POST" action="{{ route('admin.circle-joining-requests.reject-cd', $record->id) }}" class="d-inline" onsubmit="const r = prompt('Enter rejection reason (required):'); if (!r || !r.trim()) { return false; } this.querySelector('input[name=reason]').value = r.trim(); return true;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                    @endif
                    @if($canApproveId)
                        <form method="POST" action="{{ route('admin.circle-joining-requests.approve-id', $record->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                        <form method="POST" action="{{ route('admin.circle-joining-requests.reject-id', $record->id) }}" class="d-inline" onsubmit="const r = prompt('Enter rejection reason (required):'); if (!r || !r.trim()) { return false; } this.querySelector('input[name=reason]').value = r.trim(); return true;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                    @endif
                </div>
            </div>

            <p>Email: {{ $record->user?->email }} | Phone: {{ $record->user?->phone }}</p>
            <p>Company: {{ $record->user?->adminCompanyLabel() }} | City: {{ $record->user?->adminCityLabel() }}</p>
            <p>Circle: {{ $record->circle?->name }}</p>
            <p>Reason: {{ $record->reason_for_joining }}</p>
            <p>Status: <span class="badge text-bg-secondary">{{ $statusLabels[$record->status] ?? $record->status }}</span></p>
            <p>
                <strong>DED Approval:</strong>
                @php($dedApprovalStatus = $record->effectiveDedApprovalStatus())
                @if($dedApprovalStatus === 'approved')
                    <span class="badge text-bg-success">Approved</span>
                    <span class="text-success small">Approved{{ $record->dedApprovedBy ? ' by ' . $record->dedApprovedBy->adminDisplayName() : ' by DED' }}</span>
                @elseif($dedApprovalStatus === 'rejected')
                    <span class="badge text-bg-danger">Rejected</span>
                @else
                    <span class="badge text-bg-warning">Pending</span>
                @endif
            </p>

            @if(($categoryPath['level1'] ?? null) || ($categoryPath['level2'] ?? null) || ($categoryPath['level3'] ?? null) || ($categoryPath['level4'] ?? null))
                <hr>
                <h6>Selected Category</h6>
                <ul class="mb-3">
                    @if($categoryPath['level1'] ?? null)
                        <li>{{ $categoryPath['level1']->name }}</li>
                    @endif
                    @if($categoryPath['level2'] ?? null)
                        <li>→ {{ $categoryPath['level2']->name }}</li>
                    @endif
                    @if($categoryPath['level3'] ?? null)
                        <li>→ {{ $categoryPath['level3']->name }}</li>
                    @endif
                    @if($categoryPath['level4'] ?? null)
                        <li>→ {{ $categoryPath['level4']->name }}</li>
                    @endif
                </ul>
            @endif

            <p>Payment Status: @php($paymentStatus = $record->paymentStatusLabel()) <span class="badge {{ $paymentStatus === 'Paid' ? 'text-bg-success' : ($paymentStatus === 'Unpaid' ? 'text-bg-warning' : 'text-bg-secondary') }}">{{ $paymentStatus }}</span></p>

            @if($canApproveDed)
                <div class="card mt-3 bg-light border-warning"><div class="card-body">
                    <h6 class="text-warning">DED Decision Center</h6>
                    <form id="ded-decision-form" method="POST" action="">
                        @csrf
                        <div class="mb-3">
                            <label for="ded_remarks" class="form-label">Remarks / Notes (Required for Rejection)</label>
                            <textarea name="remarks" id="ded_remarks" class="form-control" rows="3" placeholder="Enter remarks..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-success" onclick="this.form.action='{{ route('admin.circle-joining-requests.approve-ded', $record->id) }}'; return true;">Approve</button>
                        <button type="submit" class="btn btn-danger ms-2" onclick="this.form.action='{{ route('admin.circle-joining-requests.reject-ded', $record->id) }}'; const val = document.getElementById('ded_remarks').value.trim(); if(!val) { alert('Remarks are required for rejection.'); return false; } return true;">Reject</button>
                    </form>
                </div></div>
            @endif

            <hr>
            <h6 class="mt-4">Approval Timeline</h6>
            <p><strong>Submitted At:</strong> {{ optional($record->requested_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Circle Director Approved By:</strong> {{ $record->cdApprovedBy?->adminDisplayName() ?? '—' }}</p>
            <p><strong>Circle Director Approved At:</strong> {{ optional($record->cd_approved_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Circle Director Rejected By:</strong> {{ $record->cdRejectedBy?->adminDisplayName() ?? '—' }}</p>
            <p><strong>Circle Director Rejected At:</strong> {{ optional($record->cd_rejected_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Circle Director Rejection Reason:</strong> <span class="text-danger">{{ $record->cd_rejection_reason ?: '—' }}</span></p>

            <p><strong>Industry Director Approved By:</strong> {{ $record->idApprovedBy?->adminDisplayName() ?? '—' }}</p>
            <p><strong>Industry Director Approved At:</strong> {{ optional($record->id_approved_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Industry Director Rejected By:</strong> {{ $record->idRejectedBy?->adminDisplayName() ?? '—' }}</p>
            <p><strong>Industry Director Rejected At:</strong> {{ optional($record->id_rejected_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Industry Director Rejection Reason:</strong> <span class="text-danger">{{ $record->id_rejection_reason ?: '—' }}</span></p>

            <p><strong>DED Approval Status:</strong> {{ ucfirst($record->effectiveDedApprovalStatus()) }}</p>
            <p><strong>DED Approved By:</strong> {{ $record->dedApprovedBy?->adminDisplayName() ?? '—' }}</p>
            <p><strong>DED Approved At:</strong> {{ optional($record->ded_approved_at)->format('d M Y H:i') ?: '—' }}</p>

            <p><strong>Fee Marked At:</strong> {{ optional($record->fee_marked_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Fee Paid At:</strong> {{ optional($record->fee_paid_at)->format('d M Y H:i') ?: '—' }}</p>
            <p><strong>Membership Activated At:</strong> {{ optional($record->fee_paid_at)->format('d M Y H:i') ?: '—' }}</p>
        </div></div>
    </div>
    @include('admin.circle_join_requests.partials.ded_approval_modal')
    @endsection
