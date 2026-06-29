@extends('admin.layouts.app')

@section('title', 'Brand Partner Offers')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Offers &amp; Coupon Codes</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">All Partners</a>
        <a href="{{ route('admin.brand-partners.dashboard') }}" class="btn btn-sm btn-outline-secondary">Dashboard</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="row g-3">
    <!-- Active Offers List -->
    <div class="col-md-6 col-12">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0 text-success"><i class="bi bi-check-circle-fill me-1"></i>Active Offers ({{ $activeOffers->count() }})</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @forelse($activeOffers as $partner)
                        <div class="list-group-item p-3">
                            <div class="d-flex align-items-start gap-3">
                                @if($partner->logo_url)
                                    <img src="{{ $partner->logo_url }}" alt="{{ $partner->name }}" class="img-thumbnail" style="width: 52px; height: 52px; object-fit: cover;">
                                @else
                                    <div class="bg-light rounded text-center d-flex align-items-center justify-content-center fw-bold text-secondary" style="width: 52px; height: 52px;">
                                        {{ Str::upper(Str::substr($partner->name, 0, 2)) }}
                                    </div>
                                @endif
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="fw-bold mb-0 text-dark">{{ $partner->name }}</h6>
                                        <span class="badge bg-success-subtle text-success small">Active</span>
                                    </div>
                                    <h6 class="text-success fw-bold mb-1 mt-1">{{ $partner->offer_title }}</h6>
                                    @if($partner->coupon_code)
                                        <div class="mb-2">
                                            <span class="small text-muted me-1">Code:</span>
                                            <code class="bg-light border px-2 py-0.5 rounded text-danger fw-bold">{{ $partner->coupon_code }}</code>
                                        </div>
                                    @endif
                                    <p class="text-muted small mb-2">{{ $partner->offer_description }}</p>
                                    <div class="d-flex justify-content-between align-items-center text-muted small">
                                        <span>
                                            <i class="bi bi-calendar-event me-1"></i>
                                            Validity: {{ $partner->valid_from ? $partner->valid_from->format('M d, Y') : 'Immediate' }} — {{ $partner->valid_to ? $partner->valid_to->format('M d, Y') : 'Ongoing' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-gift fs-2 d-block mb-2"></i>
                            No active coupon offers at this time.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- Expired Offers List -->
    <div class="col-md-6 col-12">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Expired Offers ({{ $expiredOffers->count() }})</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @forelse($expiredOffers as $partner)
                        <div class="list-group-item p-3">
                            <div class="d-flex align-items-start gap-3">
                                @if($partner->logo_url)
                                    <img src="{{ $partner->logo_url }}" alt="{{ $partner->name }}" class="img-thumbnail opacity-75" style="width: 52px; height: 52px; object-fit: cover;">
                                @else
                                    <div class="bg-light rounded text-center d-flex align-items-center justify-content-center fw-bold text-secondary opacity-75" style="width: 52px; height: 52px;">
                                        {{ Str::upper(Str::substr($partner->name, 0, 2)) }}
                                    </div>
                                @endif
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="fw-bold mb-0 text-muted">{{ $partner->name }}</h6>
                                        <span class="badge bg-danger-subtle text-danger small">Expired</span>
                                    </div>
                                    <h6 class="text-muted fw-bold mb-1 mt-1">{{ $partner->offer_title }}</h6>
                                    @if($partner->coupon_code)
                                        <div class="mb-2">
                                            <span class="small text-muted me-1">Code:</span>
                                            <code class="bg-light border px-2 py-0.5 rounded text-muted fw-bold text-decoration-line-through">{{ $partner->coupon_code }}</code>
                                        </div>
                                    @endif
                                    <p class="text-muted small mb-2">{{ $partner->offer_description }}</p>
                                    <div class="d-flex justify-content-between align-items-center text-muted small">
                                        <span>
                                            <i class="bi bi-calendar-event me-1"></i>
                                            Expired on: {{ $partner->valid_to ? $partner->valid_to->format('M d, Y') : '—' }}
                                        </span>
                                        @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
                                            <a href="{{ route('admin.brand-partners.edit', $partner) }}#offer" class="btn btn-sm btn-outline-secondary py-0.5 px-2">Renew</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-gift fs-2 d-block mb-2"></i>
                            No expired coupon offers.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
