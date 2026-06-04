@extends('admin.layouts.app')

@section('title', 'Industry Director Dashboard')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <p class="text-muted mb-1">Industry Director</p>
            <h1 class="h3 mb-0">{{ $industry?->name ?? 'Assigned Industry' }} Dashboard</h1>
            <div class="text-muted small mt-1">
                Includes selected industry{{ $industryCount > 1 ? ' and '.($industryCount - 1).' child industries/categories' : '' }}.
            </div>
        </div>
        @if (($assignedIndustries ?? collect())->count() > 1)
            <form method="POST" action="{{ route('admin.industry-director.switch-industry') }}" class="d-flex align-items-end gap-2">
                @csrf
                <div>
                    <label for="selected-industry-id" class="form-label small text-muted mb-1">Selected industry</label>
                    <select id="selected-industry-id" name="selected_industry_id" class="form-select" onchange="this.form.submit()">
                        @foreach ($assignedIndustries as $assignedIndustry)
                            <option value="{{ $assignedIndustry->id }}" @selected((string) $selectedIndustryId === (string) $assignedIndustry->id)>
                                {{ $assignedIndustry->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <noscript>
                    <button type="submit" class="btn btn-primary">Switch</button>
                </noscript>
            </form>
        @endif
    </div>

    <div class="row g-3">
        @foreach ([
            ['label' => 'Total Industry Members', 'value' => $metrics['total_industry_members'], 'icon' => 'bi-people'],
            ['label' => 'Active Members', 'value' => $metrics['active_members'], 'icon' => 'bi-person-check'],
            ['label' => 'New Registrations', 'value' => $metrics['new_registrations'], 'icon' => 'bi-person-plus'],
            ['label' => 'Total Activities', 'value' => $metrics['total_activities'], 'icon' => 'bi-activity'],
            ['label' => 'Total Posts', 'value' => $metrics['total_posts'], 'icon' => 'bi-chat-dots'],
            ['label' => 'Pending Requests Count', 'value' => $metrics['pending_requests_count'], 'icon' => 'bi-hourglass-split'],
            ['label' => 'Total Circles', 'value' => $metrics['total_circles'], 'icon' => 'bi-diagram-3'],
            ['label' => 'Total Coins Earned', 'value' => $metrics['total_coins_earned'], 'icon' => 'bi-coin'],
            ['label' => 'Life Impact', 'value' => $metrics['life_impact'], 'icon' => 'bi-heart-pulse'],
        ] as $card)
            <div class="col-sm-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="bi {{ $card['icon'] }} fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">{{ $card['label'] }}</div>
                            <div class="fs-3 fw-semibold">{{ number_format((float) $card['value']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
