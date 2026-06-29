@extends('admin.layouts.app')

@section('title', 'Brand Partner Settings')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Module Settings</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.dashboard') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">All Partners</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-8 col-12 mx-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-secondary"><i class="bi bi-gear-fill me-1"></i>Configuration Parameters</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.brand-partners.settings.update') }}">
                    @csrf
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">CPC (Cost Per Click) Billing Rate ($)</label>
                            <input type="number" step="0.01" name="cpc_rate" class="form-control" value="{{ old('cpc_rate', $settings['cpc_rate'] ?? 0.10) }}" min="0">
                            <div class="form-text small">Standard fee billed to partners per click redirect.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">CPM (Cost Per Mille) Billing Rate ($)</label>
                            <input type="number" step="0.01" name="cpm_rate" class="form-control" value="{{ old('cpm_rate', $settings['cpm_rate'] ?? 1.00) }}" min="0">
                            <div class="form-text small">Standard fee billed to partners per 1,000 banner impressions.</div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold">Offer Expiry Alert Days</label>
                            <input type="number" name="expiry_warning_days" class="form-control" value="{{ old('expiry_warning_days', $settings['expiry_warning_days'] ?? 3) }}" min="1" max="30" required>
                            <div class="form-text small">Alert partners/admins N days before coupon validity expires.</div>
                        </div>

                        <div class="col-md-12 border-top pt-3 mt-4">
                            <h6 class="fw-bold text-secondary">Future monetization modules toggles</h6>
                        </div>

                        <div class="col-md-12">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_qr_redemption" name="enable_qr_redemption" value="1" @checked(old('enable_qr_redemption', $settings['enable_qr_redemption'] ?? false))>
                                <label class="form-check-label" for="enable_qr_redemption">Enable QR Code Voucher Redemptions</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_geo_targeting" name="enable_geo_targeting" value="1" @checked(old('enable_geo_targeting', $settings['enable_geo_targeting'] ?? false))>
                                <label class="form-check-label" for="enable_geo_targeting">Enable Localized Geo-Targeting Recommendations</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_recommendations" name="enable_recommendations" value="1" @checked(old('enable_recommendations', $settings['enable_recommendations'] ?? false))>
                                <label class="form-check-label" for="enable_recommendations">Enable Personalized Campaign Recommendations</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 border-top pt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Configuration</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
