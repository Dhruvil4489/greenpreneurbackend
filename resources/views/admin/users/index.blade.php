@extends('admin.layouts.app')

@section('title', 'Peers')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('info'))
    <div class="alert alert-info">{{ session('info') }}</div>
@endif
@if(session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="card p-3">
    <form id="usersFiltersForm" method="GET" class="d-none">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
    </form>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div class="d-flex align-items-center gap-2">
            <label for="perPage" class="form-label mb-0 small text-muted">Rows per page:</label>
            <select id="perPage" name="per_page" class="form-select form-select-sm" style="width: 90px;">
                @foreach ([10, 20, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div class="small text-muted">
            @if($users->total() > 0)
                Records {{ $users->firstItem() }} to {{ $users->lastItem() }} of {{ $users->total() }}
            @else
                No records found
            @endif
        </div>
        <div class="d-flex flex-column align-items-end gap-2 ms-auto">
            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                <a href="{{ route('admin.users.import') }}" class="btn btn-outline-primary btn-sm">Import</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="exportCsvBtn">Export CSV</button>
                <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">Add Peer</a>
            </div>
            <div class="d-flex align-items-end gap-2 flex-wrap justify-content-end">
                <div class="d-flex flex-column gap-1" style="min-width: 200px;">
                    <label for="joinedFilter" class="form-label form-label-sm mb-0 text-muted small">Date Filter</label>
                    <select name="joined_filter" id="joinedFilter" form="usersFiltersForm" class="form-select form-select-sm">
                        <option value="all" @selected(($filters['joined_filter'] ?? 'all') === 'all')>All Joined Dates</option>
                        <option value="last_month" @selected(($filters['joined_filter'] ?? 'all') === 'last_month')>Last Month</option>
                        <option value="last_week" @selected(($filters['joined_filter'] ?? 'all') === 'last_week')>Last Week</option>
                        <option value="yesterday" @selected(($filters['joined_filter'] ?? 'all') === 'yesterday')>Yesterday</option>
                        <option value="custom" @selected(($filters['joined_filter'] ?? 'all') === 'custom')>Custom Range</option>
                    </select>
                </div>
                <div id="joinedCustomRange" class="d-flex gap-2 flex-wrap">
                    <div class="d-flex flex-column gap-1">
                        <label for="joinedFrom" class="form-label form-label-sm mb-0 text-muted small">From</label>
                        <input id="joinedFrom" type="date" name="joined_from" form="usersFiltersForm" class="form-control form-control-sm" value="{{ request('joined_from', $filters['joined_from'] ?? '') }}">
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <label for="joinedTo" class="form-label form-label-sm mb-0 text-muted small">To</label>
                        <input id="joinedTo" type="date" name="joined_to" form="usersFiltersForm" class="form-control form-control-sm" value="{{ request('joined_to', $filters['joined_to'] ?? '') }}">
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" form="usersFiltersForm" class="btn btn-sm btn-primary">Apply</button>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.users.index') }}">Reset</a>
                </div>
            </div>
        </div>
    </div>
    <form id="exportCsvForm" method="POST" action="{{ route('admin.users.export.csv') }}" class="d-none">
        @csrf
        <input type="hidden" name="q" value="{{ $filters['search'] }}">
        <input type="hidden" name="membership_status" value="{{ $filters['membership_status'] ?? '' }}">
        <input type="hidden" name="circle_id" value="{{ $filters['circle_id'] ?? 'all' }}">
        <input type="hidden" name="phone" value="{{ $filters['phone'] ?? '' }}">
        <input type="hidden" name="joined_filter" value="{{ $filters['joined_filter'] ?? 'all' }}">
        <input type="hidden" name="joined_from" value="{{ $filters['joined_from'] ?? '' }}">
        <input type="hidden" name="joined_to" value="{{ $filters['joined_to'] ?? '' }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
    </form>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" class="form-check-input" id="select-all">
                    </th>
                    <th>
                        <a href="{{ route('admin.users.index', array_merge(request()->query(), ['sort' => 'display_name', 'dir' => $filters['sort'] === 'display_name' && $filters['dir'] === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            Peer Name
                            @if ($filters['sort'] === 'display_name')
                                <i class="bi bi-arrow-{{ $filters['dir'] === 'asc' ? 'up' : 'down' }}-short"></i>
                            @endif
                        </a>
                    </th>
                    <th>Phone</th>
                    <th>Membership</th>
                    <th>
                        <a href="{{ route('admin.users.index', array_merge(request()->query(), ['sort' => 'coins_balance', 'dir' => $filters['sort'] === 'coins_balance' && $filters['dir'] === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            Coins
                            @if ($filters['sort'] === 'coins_balance')
                                <i class="bi bi-arrow-{{ $filters['dir'] === 'asc' ? 'up' : 'down' }}-short"></i>
                            @endif
                        </a>
                    </th>
                    <th>
                        <a href="{{ route('admin.users.index', array_merge(request()->query(), ['sort' => 'last_login_at', 'dir' => $filters['sort'] === 'last_login_at' && $filters['dir'] === 'asc' ? 'desc' : 'asc'])) }}" class="text-decoration-none text-dark">
                            Last Login
                            @if ($filters['sort'] === 'last_login_at')
                                <i class="bi bi-arrow-{{ $filters['dir'] === 'asc' ? 'up' : 'down' }}-short"></i>
                            @endif
                        </a>
                    </th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                <tr class="bg-light align-middle">
                    <th></th>
                    <th>
                        <div class="d-flex flex-column gap-2" style="min-width:220px;">
                            <input type="text" name="q" form="usersFiltersForm" value="{{ $q ?? '' }}" class="form-control form-control-sm" placeholder="Peer/Company/City">
                            <select name="circle_id" form="usersFiltersForm" class="form-select form-select-sm">
                                <option value="all">All Circles</option>
                                @foreach($circles as $c)
                                    <option value="{{ $c->id }}" @selected(($circleId ?? 'all') == $c->id)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </th>
                    <th>
                        <input type="text" name="phone" form="usersFiltersForm" class="form-control form-control-sm" placeholder="Phone" value="{{ $filters['phone'] }}">
                    </th>
                    <th>
                        <select name="membership_status" form="usersFiltersForm" class="form-select form-select-sm">
                            <option value="all">All</option>
                            @foreach ($membershipStatuses as $status)
                                <option value="{{ $status }}" @selected($filters['membership_status'] === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th>
                        <input type="text" name="coins_balance" class="form-control form-control-sm" placeholder="—" disabled>
                    </th>
                    <th>
                        <select class="form-select form-select-sm" disabled><option>Any</option></select>
                    </th>
                    <th>
                        <select class="form-select form-select-sm" disabled><option>Any</option></select>
                    </th>
                    <th class="text-end">
                        <span class="text-muted small">Use controls above</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    @php
                        $name = $user->name ?? trim((($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
                        $avatar = $user->profile_photo_url ?? ($user->profile_photo_file_id ? url('/api/v1/files/' . $user->profile_photo_file_id) : null);
                        $cityName = $user->city->name ?? $user->city ?? 'No City';
                        $company = $user->company_name ?? $user->company ?? $user->business_name ?? 'No Company';
                        $circleName = optional($user->circleMembers->first()?->circle)->name ?? 'No Circle';
                        $statusValue = $user->status ?? 'active';
                        $isActive = $statusValue === 'active';
                        $detailsId = 'details-' . $user->id;
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input row-checkbox" value="{{ $user->id }}">
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center border" style="width: 36px; height: 36px; overflow: hidden;">
                                    @if ($avatar)
                                        <img src="{{ $avatar }}" alt="{{ $name }}" class="img-fluid w-100 h-100 object-fit-cover">
                                    @else
                                        <span class="text-muted">{{ strtoupper(substr($name, 0, 1)) }}</span>
                                    @endif
                                </div>
                                <div class="d-flex flex-column">
                                    <div class="fw-semibold text-dark">{{ $name !== '' ? $name : '—' }}</div>
                                    <div class="text-muted small">{{ $company }}</div>
                                    <div class="text-muted small">{{ $cityName }}</div>
                                    <div class="text-muted small">{{ $circleName }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{{ $user->phone ?? '—' }}</td>
                        <td>
                            <span class="badge bg-primary-subtle text-primary text-uppercase">{{ $user->membership_status ?? 'Free' }}</span>
                        </td>
                        <td>{{ number_format($user->coins_balance ?? 0) }}</td>
                        <td>{{ optional($user->last_login_at)->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                {{ $isActive ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group">
                                @if ($canEditUsers)
                                    <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">Edit</a>
                                @endif
                                <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $detailsId }}" aria-expanded="false" aria-controls="{{ $detailsId }}">Details</button>
                            </div>
                        </td>
                    </tr>
                    <tr class="collapse-row">
                        <td colspan="8" class="p-0 border-0">
                            <div class="collapse" id="{{ $detailsId }}">
                                <div class="p-3 bg-light border-top">
                                    @php
                                        $joinedCircle = $user->circleMembers->first()?->circle;
                                        $joinedCircleName = $joinedCircle?->name;
                                        $joinedCircleId = $joinedCircle?->id;
                                        $joinedCircleCategoryTrees = collect($joinedCircleCategoryTreesByUserId[(string) $user->id] ?? []);

                                        $fields = [
                                            ['label' => 'ID', 'value' => $user->id],
                                            ['label' => 'Email', 'value' => $user->email],
                                            ['label' => 'Phone', 'value' => $user->phone],
                                            ['label' => 'First Name', 'value' => $user->first_name],
                                            ['label' => 'Last Name', 'value' => $user->last_name],
                                            ['label' => 'Display Name', 'value' => $user->display_name],
                                            ['label' => 'Designation', 'value' => $user->designation],
                                            ['label' => 'Company Name', 'value' => $user->company_name],
                                            ['label' => 'Profile Photo URL', 'value' => $user->profile_photo_url],
                                            ['label' => 'Profile Photo File ID', 'value' => $user->profile_photo_file_id],
                                            ['label' => 'Cover Photo File ID', 'value' => $user->cover_photo_file_id],
                                            ['label' => 'Short Bio', 'value' => $user->short_bio],
                                            ['label' => 'Long Bio (HTML)', 'value' => $user->long_bio_html],
                                            ['label' => 'Industry Tags', 'value' => $user->industry_tags, 'type' => 'json'],
                                            ['label' => 'Business Type', 'value' => $user->business_type],
                                            ['label' => 'Turnover Range', 'value' => $user->turnover_range],
                                            ['label' => 'City ID', 'value' => $user->city_id],
                                            ['label' => 'City (string)', 'value' => $user->city],
                                            ['label' => 'Membership Status', 'value' => $user->membership_status],
                                            ['label' => 'Membership Ends At', 'value' => $user->membership_ends_at, 'type' => 'membership_date'],
                                            ['label' => 'Circles', 'value' => $joinedCircleName ?: 'No Circle', 'circle_id' => $joinedCircleId],
                                            ['label' => 'Zoho Customer ID', 'value' => $user->zoho_customer_id],
                                            ['label' => 'Zoho Subscription ID', 'value' => $user->zoho_subscription_id],
                                            ['label' => 'Zoho Plan Code', 'value' => $user->zoho_plan_code],
                                            ['label' => 'Zoho Last Invoice ID', 'value' => $user->zoho_last_invoice_id],
                                            ['label' => 'Membership Starts At', 'value' => $user->membership_starts_at, 'type' => 'membership_date'],
                                            ['label' => 'Last Payment At', 'value' => $user->last_payment_at, 'type' => 'membership_date'],
                                            ['label' => 'Coins Balance', 'value' => $user->coins_balance],
                                            ['label' => 'Total Life Impacted', 'value' => $user->life_impacted_count],
                                            ['label' => 'Medal Rank', 'value' => $user->coin_medal_rank],
                                            ['label' => 'Title', 'value' => $user->coin_milestone_title],
                                            ['label' => 'Meaning & Vibe', 'value' => $user->coin_milestone_meaning],
                                            ['label' => 'Introduced By', 'value' => $user->introduced_by],
                                            ['label' => 'Members Introduced Count', 'value' => $user->members_introduced_count],
                                            ['label' => 'Contribution Award Name', 'value' => $user->contribution_award_name],
                                            ['label' => 'Contribution Recognition', 'value' => $user->contribution_award_recognition],
                                            ['label' => 'Influencer Stars', 'value' => $user->influencer_stars],
                                            ['label' => 'Target Regions', 'value' => $user->target_regions, 'type' => 'json'],
                                            ['label' => 'Target Business Categories', 'value' => $user->target_business_categories, 'type' => 'json'],
                                            ['label' => 'Hobbies / Interests', 'value' => $user->hobbies_interests, 'type' => 'json'],
                                            ['label' => 'Leadership Roles', 'value' => $user->leadership_roles, 'type' => 'json'],
                                            ['label' => 'Is Sponsored Member', 'value' => $user->is_sponsored_member, 'type' => 'bool'],
                                            ['label' => 'Public Profile Slug', 'value' => $user->public_profile_slug],
                                            ['label' => 'Special Recognitions', 'value' => $user->special_recognitions, 'type' => 'json'],
                                            ['label' => 'Gender', 'value' => $user->gender],
                                            ['label' => 'Date of Birth', 'value' => $user->dob, 'type' => 'date'],
                                            ['label' => 'Experience (years)', 'value' => $user->experience_years],
                                            ['label' => 'Experience Summary', 'value' => $user->experience_summary],
                                            ['label' => 'Skills', 'value' => $user->skills, 'type' => 'json'],
                                            ['label' => 'Interests', 'value' => $user->interests, 'type' => 'json'],
                                            ['label' => 'GDPR Deleted At', 'value' => $user->gdpr_deleted_at, 'type' => 'date'],
                                            ['label' => 'Anonymized At', 'value' => $user->anonymized_at, 'type' => 'date'],
                                            ['label' => 'Is GDPR Exported', 'value' => $user->is_gdpr_exported, 'type' => 'bool'],
                                            ['label' => 'Last Login', 'value' => $user->last_login_at, 'type' => 'date'],
                                            ['label' => 'Created At', 'value' => $user->created_at, 'type' => 'date'],
                                            ['label' => 'Updated At', 'value' => $user->updated_at, 'type' => 'date'],
                                            ['label' => 'Deleted At', 'value' => $user->deleted_at, 'type' => 'date'],
                                        ];

                                        $chunks = array_chunk($fields, (int) ceil(count($fields) / 2));
                                        $renderValue = function ($value, $type = 'text') {
                                            $normalizeText = static function ($input) {
                                                if (! is_string($input)) {
                                                    return $input;
                                                }

                                                $trimmed = trim($input);

                                                return $trimmed === '' ? null : $trimmed;
                                            };

                                            if ($type === 'bool') {
                                                $class = $value ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
                                                $label = $value ? 'Yes' : 'No';
                                                return '<span class="badge ' . $class . '">' . $label . '</span>';
                                            }

                                            if ($type === 'date') {
                                                $value = $normalizeText($value);

                                                if (! $value) {
                                                    return '—';
                                                }

                                                $isDate = $value instanceof \DateTimeInterface;
                                                $formatted = $isDate ? $value->format('Y-m-d H:i') : (string) $value;
                                                $raw = $isDate && method_exists($value, 'toDateTimeString') ? $value->toDateTimeString() : (string) $value;
                                                return e($formatted) . ' <span class="text-muted small">(' . e($raw) . ')</span>';
                                            }

                                            if ($type === 'membership_date') {
                                                $value = $normalizeText($value);

                                                if (! $value) {
                                                    return '—';
                                                }

                                                return e($value instanceof \DateTimeInterface ? $value->format('d-m-Y H:i') : (string) $value);
                                            }

                                            if ($type === 'json') {
                                                if (is_null($value)) {
                                                    return '—';
                                                }

                                                if (is_array($value) && $value !== []) {
                                                    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
                                                    if ($isAssoc) {
                                                        $rendered = collect($value)->map(fn ($v, $k) => $k . ': ' . $v)->implode(', ');
                                                    } else {
                                                        $rendered = implode(', ', $value);
                                                    }
                                                    return e($rendered);
                                                }

                                                return '—';
                                            }

                                            $value = $normalizeText($value);

                                            if ($value === null) {
                                                return '—';
                                            }

                                            return e((string) $value);
                                        };
                                    @endphp
                                    <div class="row g-3">
                                        @foreach ($chunks as $chunk)
                                            <div class="col-md-6">
                                                <table class="table table-sm mb-0">
                                                    @foreach ($chunk as $field)
                                                        <tr>
                                                            <th class="w-50 text-muted">{{ $field['label'] }}</th>
                                                            <td class="text-break">
                                                                @if (($field['label'] ?? null) === 'Circles' && ! empty($field['circle_id']))
                                                                    <div class="d-flex align-items-center gap-2">
                                                                        <span>{{ $field['value'] }}</span>
                                                                        <a href="{{ route('admin.circles.edit', $field['circle_id']) }}" class="btn btn-sm btn-outline-primary">View</a>
                                                                    </div>
                                                                @else
                                                                    {!! $renderValue($field['value'], $field['type'] ?? 'text') !!}
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </table>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-3">
                                        <h6 class="mb-2">Joined Circle Categories</h6>
                                        @php
                                            $registeredMainBusinessCategory = $user->mainBusinessCategory;
                                            $registeredBusinessCategory = $user->businessCategory;
                                            $hasRegisteredBusinessCategory = $registeredMainBusinessCategory || $registeredBusinessCategory;
                                        @endphp
                                        @if($joinedCircleCategoryTrees->isEmpty() && ! $hasRegisteredBusinessCategory)
                                            <div class="text-muted">—</div>
                                        @else
                                            @if($hasRegisteredBusinessCategory)
                                                <div class="border rounded p-3 bg-light-subtle mb-3">
                                                    <div class="fw-semibold mb-2">Registered Business Category</div>
                                                    <div class="small">
                                                        {{ $registeredMainBusinessCategory?->name ?? '—' }}
                                                        @if($registeredBusinessCategory)
                                                            <span class="text-muted mx-1">→</span>
                                                            {{ $registeredBusinessCategory->name }}
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif

                                            @if($joinedCircleCategoryTrees->isNotEmpty())
                                            <div class="row g-3">
                                                @foreach($joinedCircleCategoryTrees as $circleTree)
                                                    <div class="col-12">
                                                        <div class="border rounded p-3 bg-light-subtle">
                                                            <div class="fw-semibold mb-2">
                                                                Joined Circle: {{ $circleTree['circle']?->name ?: ($circleTree['membership']->circle?->name ?? '—') }}
                                                            </div>

                                                            @if(($circleTree['categories'] ?? collect())->isEmpty())
                                                                <div class="text-muted">—</div>
                                                            @else
                                                                @foreach($circleTree['categories'] as $mainCategoryTree)
                                                                    <div class="mb-0">
                                                                        <span class="badge bg-light text-dark border mb-2">
                                                                            Category: {{ $mainCategoryTree['node']->name }}
                                                                        </span>
                                                                        @if(($mainCategoryTree['children'] ?? collect())->isNotEmpty())
                                                                            <ul class="mb-0">
                                                                                @foreach($mainCategoryTree['children'] as $level2Tree)
                                                                                    <li>
                                                                                        {{ $level2Tree['node']->name }}
                                                                                        @if(($level2Tree['children'] ?? collect())->isNotEmpty())
                                                                                            <ul>
                                                                                                @foreach($level2Tree['children'] as $level3Tree)
                                                                                                    <li>
                                                                                                        {{ $level3Tree['node']->name }}
                                                                                                        @if(($level3Tree['children'] ?? collect())->isNotEmpty())
                                                                                                            <ul>
                                                                                                                @foreach($level3Tree['children'] as $level4Node)
                                                                                                                    <li>{{ $level4Node->name }}</li>
                                                                                                                @endforeach
                                                                                                            </ul>
                                                                                                        @endif
                                                                                                    </li>
                                                                                                @endforeach
                                                                                            </ul>
                                                                                        @endif
                                                                                    </li>
                                                                                @endforeach
                                                                            </ul>
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                            @endif
                                        @endif
                                    </div>
                                    @include('admin.users.partials.membership_welcome_email_card', [
                                        'user' => $user,
                                        'showSendButton' => $canEditUsers,
                                        'cardClass' => 'mt-3 border-0 shadow-sm',
                                        'headerClass' => 'bg-white',
                                        'bodyClass' => '',
                                        'sendButtonClass' => 'btn btn-outline-primary btn-sm',
                                    ])
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
        <div>
            {{ $users->withQueryString()->links() }}
        </div>
        <div class="small text-muted">
            @if($users->total() > 0)
                Showing {{ $users->firstItem() }}-{{ $users->lastItem() }} of {{ $users->total() }} records
            @else
                No records
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('select-all');
        const checkboxes = document.querySelectorAll('.row-checkbox');
        const perPage = document.getElementById('perPage');
        const filterForm = document.getElementById('usersFiltersForm');
        const exportBtn = document.getElementById('exportCsvBtn');
        const exportForm = document.getElementById('exportCsvForm');
        const joinedFilter = document.getElementById('joinedFilter');
        const joinedCustomRange = document.getElementById('joinedCustomRange');
        const submitFilters = (form) => {
            const params = new URLSearchParams(window.location.search);
            const formData = new FormData(form);
            for (const [key, value] of formData.entries()) {
                params.set(key, value);
            }
            params.delete('page');
            window.location = `${window.location.pathname}?${params.toString()}`;
        };

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                checkboxes.forEach(cb => cb.checked = selectAll.checked);
            });
        }

        if (perPage) {
            perPage.addEventListener('change', () => {
                const params = new URLSearchParams(window.location.search);
                params.set('per_page', perPage.value);
                window.location = `${window.location.pathname}?${params.toString()}`;
            });
        }

        filterForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            submitFilters(filterForm);
        });

        const toggleJoinedDateRange = () => {
            if (!joinedCustomRange || !joinedFilter) return;
            const isCustom = joinedFilter.value === 'custom';
            joinedCustomRange.classList.toggle('d-none', !isCustom);
            joinedCustomRange.querySelectorAll('input').forEach((input) => {
                input.disabled = !isCustom;
            });
        };

        joinedFilter?.addEventListener('change', toggleJoinedDateRange);
        toggleJoinedDateRange();

        exportBtn?.addEventListener('click', () => {
            if (!exportForm) return;
            exportForm.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
            const selected = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value).filter(Boolean);
            selected.forEach(id => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'ids[]';
                hidden.value = id;
                exportForm.appendChild(hidden);
            });
            exportForm.submit();
        });

        const tooltipTriggerList = Array.from(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>
@endpush
@endsection
