@extends('admin.layouts.app')

@section('title', 'Brand Partner: ' . $brand_partner->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Partner Details: {{ $brand_partner->name }}</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">Back to List</a>
        @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
            <a href="{{ route('admin.brand-partners.edit', $brand_partner) }}" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i>Edit Partner</a>
        @endif
    </div>
</div>

<div class="row g-3">
    <!-- Left Column: Metrics & Analytics Card -->
    <div class="col-md-4 col-12">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-secondary"><i class="bi bi-graph-up me-1"></i>Performance Metrics</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Total Views</span>
                    <span class="fw-bold">{{ number_format($analytics['views']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Total Clicks</span>
                    <span class="fw-bold">{{ number_format($analytics['clicks']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Website Clicks</span>
                    <span>{{ number_format($analytics['website_clicks']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Redemptions</span>
                    <span>{{ number_format($analytics['redeem_clicks']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Shares</span>
                    <span>{{ number_format($analytics['shares']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span class="text-muted">Saved / Bookmarked</span>
                    <span>{{ number_format($analytics['saves']) }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2 mt-2">
                    <span class="text-muted fw-bold">Click-Through Rate (CTR)</span>
                    <span class="fw-bold text-success">{{ $analytics['ctr'] }}%</span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted fw-bold">Redeem Conversion Rate</span>
                    <span class="fw-bold text-primary">{{ $analytics['conversion_rate'] }}%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Profile details -->
    <div class="col-md-8 col-12">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-4 align-items-center mb-4">
                    @if($brand_partner->logo_url)
                        <img src="{{ $brand_partner->logo_url }}" alt="Logo" class="img-thumbnail rounded-circle" style="width: 100px; height: 100px; object-fit: cover;">
                    @else
                        <div class="bg-light rounded-circle text-center d-flex align-items-center justify-content-center fw-bold text-secondary fs-3" style="width: 100px; height: 100px;">
                            {{ Str::upper(Str::substr($brand_partner->name, 0, 2)) }}
                        </div>
                    @endif
                    <div>
                        <h4 class="fw-bold mb-1">{{ $brand_partner->name }}</h4>
                        <p class="text-muted mb-2">/{{ $brand_partner->slug }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge" style="background-color: {{ $brand_partner->category?->color ?? '#999999' }}15; color: {{ $brand_partner->category?->color ?? '#666666' }}">
                                {{ $brand_partner->category?->name ?? 'Uncategorized' }}
                            </span>
                            @if($brand_partner->is_active)
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Draft</span>
                            @endif
                            @if($brand_partner->is_featured)
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Featured</span>
                            @endif
                            @if($brand_partner->is_sponsored)
                                <span class="badge bg-info-subtle text-info border border-info-subtle">Sponsored</span>
                            @endif
                            @if($brand_partner->is_verified)
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Verified</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($brand_partner->cover_image_url)
                    <div class="mb-4">
                        <img src="{{ $brand_partner->cover_image_url }}" alt="Cover" class="img-fluid rounded w-100" style="max-height: 250px; object-fit: cover;">
                    </div>
                @endif

                <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3">General Description</h6>
                <p class="fw-medium text-dark">{{ $brand_partner->short_description }}</p>
                <p class="text-muted" style="white-space: pre-line;">{{ $brand_partner->description }}</p>

                <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3 mt-4">Contact &amp; Business Info</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="text-muted small d-block">Website</span>
                        @if($brand_partner->website)
                            <a href="{{ $brand_partner->website }}" target="_blank" rel="noopener">{{ $brand_partner->website }}</a>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted small d-block">Email</span>
                        <span>{{ $brand_partner->contact_email ?? '—' }}</span>
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted small d-block">Phone</span>
                        <span>{{ $brand_partner->contact_number ?? '—' }}</span>
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted small d-block">WhatsApp</span>
                        <span>{{ $brand_partner->whatsapp ?? '—' }}</span>
                    </div>
                    <div class="col-12">
                        <span class="text-muted small d-block">Address</span>
                        <span>{{ $brand_partner->address ?? '—' }}</span>
                    </div>
                </div>

                @if($brand_partner->offer_title)
                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3 mt-4">Promotion &amp; Coupon Offer</h6>
                    <div class="card bg-success-subtle border-success-subtle p-3 mb-3">
                        <h5 class="fw-bold text-success mb-1">{{ $brand_partner->offer_title }}</h5>
                        <p class="text-dark small mb-2">{{ $brand_partner->offer_description }}</p>
                        @if($brand_partner->coupon_code)
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="text-muted small">Code:</span>
                                <code class="bg-white border px-2.5 py-1.5 rounded text-danger fw-bold fs-6">{{ $brand_partner->coupon_code }}</code>
                            </div>
                        @endif
                        @if($brand_partner->terms_and_conditions)
                            <div class="border-top border-success-subtle pt-2 mt-2">
                                <span class="text-muted small d-block fw-semibold">Terms &amp; Conditions:</span>
                                <span class="text-muted small">{{ $brand_partner->terms_and_conditions }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
