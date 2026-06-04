@extends('admin.layouts.app')

@section('title', 'Activities')

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Top 5 District Peers</strong>
            <span class="small text-muted">Ranked by combined activity performance</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">Rank</th>
                        <th>Peer Name</th>
                        <th>Company</th>
                        <th>City</th>
                        <th class="text-end">Performance Score</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($topDistrictPeers ?? collect()) as $rank => $peer)
                        <tr>
                            <td>#{{ $rank + 1 }}</td>
                            <td>{{ $peer->peer_name }}</td>
                            <td>{{ $peer->company_name ?: '—' }}</td>
                            <td>{{ $peer->city_name ?: '—' }}</td>
                            <td class="text-end fw-semibold">{{ number_format((int) ($peer->performance_score ?? 0)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No district peer performance found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <form id="activitiesFiltersForm" method="GET" action="{{ route('admin.activities.index') }}">
        </form>

        <div class="d-flex flex-wrap justify-content-between align-items-center p-3 gap-2 border-bottom">
            <div class="d-flex align-items-center gap-2">
                <label for="perPage" class="form-label mb-0 small text-muted">Rows per page:</label>
                <select id="perPage" name="per_page" form="activitiesFiltersForm" class="form-select form-select-sm" style="width: 90px;">
                    @foreach ([10, 20, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <div class="d-flex gap-2 align-items-center">
                <input
                    type="datetime-local"
                    name="from"
                    form="activitiesFiltersForm"
                    value="{{ $filters['from'] ?? '' }}"
                    class="form-control form-control-sm"
                    style="min-width: 180px;"
                    placeholder="dd-mm-yyyy :.."
                    title="From"
                >
                <input
                    type="datetime-local"
                    name="to"
                    form="activitiesFiltersForm"
                    value="{{ $filters['to'] ?? '' }}"
                    class="form-control form-control-sm"
                    style="min-width: 180px;"
                    placeholder="dd-mm-yyyy :.."
                    title="To"
                >
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="select-all-members">
                        </th>
                        <th>Peer</th>
                        <th>Testimonials</th>
                        <th>Referrals</th>
                        <th>Business Deals</th>
                        <th>P2P Meetings</th>
                        <th>Requirements</th>
                        <th>Become A Leader</th>
                        <th>Recommend A Peer</th>
                        <th>Register A Visitor</th>
                    </tr>
                    <tr class="bg-light align-middle">
                        <th></th>
                        <th>
                            <div class="d-flex flex-column gap-2">
                                <input
                                    type="text"
                                    name="q"
                                    form="activitiesFiltersForm"
                                    class="form-control form-control-sm"
                                    placeholder="Name, company, or city"
                                    value="{{ $filters['q'] ?? '' }}"
                                >
                                <div class="row g-2">
                                    <div class="col-md-12">
                                        <select name="circle_id" form="activitiesFiltersForm" class="form-select form-select-sm">
                                            <option value="any">All Circles</option>
                                            @foreach ($circles as $circle)
                                                <option value="{{ $circle->id }}" @selected(($filters['circle_id'] ?? '') === $circle->id)>{{ $circle->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th><input type="text" class="form-control form-control-sm" disabled placeholder="—"></th>
                        <th>
                            <div class="d-flex gap-2 justify-content-end">
                                <button type="submit" form="activitiesFiltersForm" class="btn btn-sm btn-primary">Apply</button>
                                <a href="{{ route('admin.activities.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#activitiesExportModal">Export</button>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            <td><input type="checkbox" class="form-check-input member-checkbox" value="{{ $member->id }}"></td>
                            <td>
                                <div class="fw-bold">{{ $member->peer_name }}</div>
                                <div class="small text-muted">{{ $member->company_name ?: '—' }}</div>
                                <div class="small text-muted">{{ $member->city_name ?: 'No City' }}</div>
                                <div class="small text-muted">{{ $member->circle_name ?: 'No Circle' }}</div>
                            </td>
                            <td>@if ($member->testimonials_count > 0)<a href="{{ route('admin.activities.testimonials', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->testimonials_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->referrals_count > 0)<a href="{{ route('admin.activities.referrals', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->referrals_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->business_deals_count > 0)<a href="{{ route('admin.activities.business-deals', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->business_deals_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->p2p_completed_count > 0)<a href="{{ route('admin.activities.p2p-meetings', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->p2p_completed_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->requirements_count > 0)<a href="{{ route('admin.activities.requirements', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->requirements_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->become_leader_count > 0)<a href="{{ route('admin.activities.become-a-leader.show', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->become_leader_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->recommend_peer_count > 0)<a href="{{ route('admin.activities.recommend-peer.show', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->recommend_peer_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                            <td>@if ($member->register_visitor_count > 0)<a href="{{ route('admin.activities.register-visitor.show', $member->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">{{ $member->register_visitor_count }}</a>@else<span class="text-muted">0</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">No peers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>



        <div class="modal fade" id="activitiesExportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Activities Summary</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="{{ route('admin.activities.export') }}" id="activitiesExportForm">
                    @csrf
                    <input type="hidden" name="activity_type" value="summary">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Scope</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scope" id="scopeSelected" value="selected" checked>
                                <label class="form-check-label" for="scopeSelected">Selected peers only</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scope" id="scopeAll" value="all">
                                <label class="form-check-label" for="scopeAll">All peers (current filters)</label>
                            </div>
                        </div>
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                        <input type="hidden" name="search" value="{{ $filters['q'] }}">
                        <input type="hidden" name="circle_id" value="{{ $filters['circle_id'] }}">
                        <input type="hidden" name="from" value="{{ $filters['from'] }}">
                        <input type="hidden" name="to" value="{{ $filters['to'] }}">
                        <div id="selectedMemberIdsContainer"></div>
                        <div class="text-danger small d-none" id="exportSelectionError">Please select at least one peer.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Export CSV</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-3">
        {{ $members->links() }}
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('select-all-members');
        const checkboxes = document.querySelectorAll('.member-checkbox');
        const exportForm = document.getElementById('activitiesExportForm');
        const selectedContainer = document.getElementById('selectedMemberIdsContainer');
        const selectionError = document.getElementById('exportSelectionError');
        const scopeSelected = document.getElementById('scopeSelected');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                checkboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
            });
        }


        exportForm.addEventListener('submit', (event) => {
            selectionError.classList.add('d-none');
            selectedContainer.innerHTML = '';
            const selectedIds = Array.from(checkboxes)
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value);

            if (scopeSelected.checked && selectedIds.length === 0) {
                event.preventDefault();
                selectionError.classList.remove('d-none');
                return;
            }

            selectedIds.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_member_ids[]';
                input.value = id;
                selectedContainer.appendChild(input);
            });
        });
    });
</script>
@endpush
