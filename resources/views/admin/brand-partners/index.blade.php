@extends('admin.layouts.app')

@section('title', 'Brand Partners')

@section('content')
<style>
    @media (min-width: 768px) {
        .bp-card-wrapper,
        .bp-table-wrapper {
            overflow: visible !important;
        }
    }
    .bp-action-dropdown {
        max-height: 380px !important;
        overflow-y: auto !important;
        z-index: 1060 !important;
    }
</style>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Brand Partners</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.brand-partners.dashboard') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.brand-partners.categories.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tags me-1"></i>Categories</a>
        <a href="{{ route('admin.brand-partners.offers') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gift me-1"></i>Offers</a>
        @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
            <a href="{{ route('admin.brand-partners.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Partner</a>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<!-- Filters & Search Card -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.brand-partners.index') }}" class="row g-2 align-items-center">
            <div class="col-md-3">
                <input type="text" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search by name, slug, or offer...">
            </div>
            <div class="col-md-2">
                <select name="category_id" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected($categoryId == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="active" @selected($status == 'active')>Active</option>
                    <option value="inactive" @selected($status == 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-1">
                <select name="featured" class="form-select form-select-sm">
                    <option value="">Featured?</option>
                    <option value="1" @selected($featured == '1')>Yes</option>
                    <option value="0" @selected($featured == '0')>No</option>
                </select>
            </div>
            <div class="col-md-1">
                <select name="sponsored" class="form-select form-select-sm">
                    <option value="">Sponsored?</option>
                    <option value="1" @selected($sponsored == '1')>Yes</option>
                    <option value="0" @selected($sponsored == '0')>No</option>
                </select>
            </div>
            <div class="col-md-1">
                <select name="has_offer" class="form-select form-select-sm">
                    <option value="">Has Offer?</option>
                    <option value="1" @selected($hasOffer == '1')>Yes</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-secondary flex-grow-1"><i class="bi bi-search me-1"></i>Filter</button>
                <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Datatable Card -->
<div class="card border-0 shadow-sm bp-card-wrapper">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-secondary">Partners List</h6>
        
        <!-- Export Dropdown -->
        @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'analytics_team']))
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="exportMenu" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="exportMenu">
                    <li><a class="dropdown-item" href="{{ route('admin.brand-partners.export', array_merge(request()->query(), ['format' => 'csv'])) }}"><i class="bi bi-filetype-csv me-2 text-primary"></i>CSV Sheet</a></li>
                    <li><a class="dropdown-item" href="{{ route('admin.brand-partners.export', array_merge(request()->query(), ['format' => 'excel'])) }}"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Sheet</a></li>
                    <li><a class="dropdown-item" target="_blank" href="{{ route('admin.brand-partners.export', array_merge(request()->query(), ['format' => 'pdf'])) }}"><i class="bi bi-filetype-pdf me-2 text-danger"></i>Print PDF</a></li>
                </ul>
            </div>
        @endif
    </div>
    
    <div class="table-responsive bp-table-wrapper">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 80px;">Logo</th>
                    <th>Brand Name</th>
                    <th>Category</th>
                    <th class="text-center">Featured</th>
                    <th class="text-center">Sponsored</th>
                    <th>Active Offer</th>
                    <th>Status</th>
                    <th class="text-center">Views</th>
                    <th class="text-center">Clicks</th>
                    <th class="text-end" style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody class="border-top-0">
                @forelse($partners as $partner)
                    <tr>
                        <td>
                            @if($partner->logo_url)
                                <img src="{{ $partner->logo_url }}" alt="{{ $partner->name }}" class="img-thumbnail rounded-circle" style="width: 48px; height: 48px; object-fit: cover;">
                            @else
                                <div class="bg-light rounded-circle text-center d-flex align-items-center justify-content-center fw-bold text-secondary" style="width: 48px; height: 48px;">
                                    {{ Str::upper(Str::substr($partner->name, 0, 2)) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-bold text-dark">{{ $partner->name }}</div>
                            <span class="text-muted small">/{{ $partner->slug }}</span>
                            @if($partner->is_verified)
                                <i class="bi bi-patch-check-fill text-primary ms-1" title="Verified Brand"></i>
                            @endif
                        </td>
                        <td>
                            <span class="badge" style="background-color: {{ $partner->category?->color ?? '#999999' }}15; color: {{ $partner->category?->color ?? '#666666' }}">
                                <i class="bi {{ $partner->category?->icon ?? 'bi-tag' }} me-1"></i>{{ $partner->category?->name ?? 'Uncategorized' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <form method="POST" action="{{ route('admin.brand-partners.toggle-featured', $partner) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="border-0 bg-transparent text-decoration-none">
                                    <i class="bi {{ $partner->is_featured ? 'bi-star-fill text-warning fs-5' : 'bi-star text-muted fs-5' }}"></i>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="POST" action="{{ route('admin.brand-partners.toggle-sponsored', $partner) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="border-0 bg-transparent text-decoration-none">
                                    <i class="bi {{ $partner->is_sponsored ? 'bi-award-fill text-info fs-5' : 'bi-award text-muted fs-5' }}"></i>
                                </button>
                            </form>
                        </td>
                        <td>
                            @if($partner->offer_title)
                                <div class="fw-medium text-success">{{ $partner->offer_title }}</div>
                                @if($partner->coupon_code)
                                    <code class="bg-light px-2 py-1 rounded small border">{{ $partner->coupon_code }}</code>
                                @endif
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $partner->is_active ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' }} px-2.5 py-1.5">
                                {{ $partner->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="text-center fw-medium">{{ number_format($partner->views_count) }}</td>
                        <td class="text-center fw-medium">{{ number_format($partner->clicks_count) }}</td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false">
                                    Actions
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm bp-action-dropdown">
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.brand-partners.show', $partner) }}"><i class="bi bi-eye me-2 text-primary"></i>View Details</a>
                                    </li>
                                    @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
                                        <li>
                                            <a class="dropdown-item" href="{{ route('admin.brand-partners.edit', $partner) }}"><i class="bi bi-pencil me-2 text-primary"></i>Edit Partner</a>
                                        </li>
                                    @endif
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.brand-partners.show', $partner) }}#analytics"><i class="bi bi-bar-chart-line me-2 text-success"></i>View Analytics</a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.duplicate', $partner) }}">
                                                @csrf
                                                <button type="submit" class="dropdown-item"><i class="bi bi-copy me-2 text-secondary"></i>Duplicate</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.toggle-status', $partner) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi {{ $partner->is_active ? 'bi-eye-slash text-warning' : 'bi-eye text-success' }} me-2"></i>
                                                    {{ $partner->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.toggle-featured', $partner) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi {{ $partner->is_featured ? 'bi-star-fill text-warning' : 'bi-star text-muted' }} me-2"></i>
                                                    {{ $partner->is_featured ? 'Unfeature' : 'Feature' }}
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.toggle-sponsored', $partner) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi {{ $partner->is_sponsored ? 'bi-award-fill text-info' : 'bi-award text-muted' }} me-2"></i>
                                                    {{ $partner->is_sponsored ? 'Unsponsor' : 'Sponsor' }}
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                    @endif
                                    <li>
                                        <button type="button" class="dropdown-item btn-copy-public-link" data-link="{{ url('/brand-partners/' . $partner->slug) }}">
                                            <i class="bi bi-link-45deg me-2 text-secondary"></i>Copy Public Link
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item btn-share-partner" data-title="{{ $partner->name }}" data-text="Check out {{ $partner->name }} on PGU! Coupon Code: {{ $partner->coupon_code ?? 'None' }}" data-url="{{ url('/brand-partners/' . $partner->slug) }}">
                                            <i class="bi bi-share me-2 text-secondary"></i>Share Partner
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item btn-copy-coupon-code" data-code="{{ $partner->coupon_code }}" @disabled(empty($partner->coupon_code))>
                                            <i class="bi bi-ticket-perforated me-2 text-success"></i>Copy Coupon Code
                                        </button>
                                    </li>
                                    @if(auth('admin')->user() && in_array(auth('admin')->user()->roles->pluck('key')->first(), ['global_admin', 'marketing_team', 'content_team']))
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.priority-up', $partner) }}">
                                                @csrf
                                                <button type="submit" class="dropdown-item"><i class="bi bi-arrow-up-circle me-2 text-secondary"></i>Priority Up</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.priority-down', $partner) }}">
                                                @csrf
                                                <button type="submit" class="dropdown-item"><i class="bi bi-arrow-down-circle me-2 text-secondary"></i>Priority Down</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.send-notification', $partner) }}" onsubmit="return confirm('Send manual push & database notification alert to all active users?')">
                                                @csrf
                                                <button type="submit" class="dropdown-item" @disabled(!$partner->is_active)><i class="bi bi-bell me-2 text-warning"></i>Send Notification</button>
                                            </form>
                                        </li>
                                    @endif
                                    @if(auth('admin')->user() && auth('admin')->user()->roles->pluck('key')->contains('global_admin'))
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="{{ route('admin.brand-partners.destroy', $partner) }}" onsubmit="return confirm('Delete this brand partner permanently? This cannot be undone.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2 text-danger"></i>Delete Partner</button>
                                            </form>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            No brand partners found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    @if($partners->hasPages())
        <div class="card-footer bg-white border-top py-3">
            {{ $partners->links() }}
        </div>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Copy Public Link
        document.querySelectorAll('.btn-copy-public-link').forEach(btn => {
            btn.addEventListener('click', function() {
                const link = this.getAttribute('data-link');
                navigator.clipboard.writeText(link).then(() => {
                    alert('Public link copied to clipboard!');
                }).catch(err => {
                    console.error('Failed to copy: ', err);
                });
            });
        });

        // Copy Coupon Code
        document.querySelectorAll('.btn-copy-coupon-code').forEach(btn => {
            btn.addEventListener('click', function() {
                const code = this.getAttribute('data-code');
                if (code) {
                    navigator.clipboard.writeText(code).then(() => {
                        alert('Coupon code "' + code + '" copied to clipboard!');
                    }).catch(err => {
                        console.error('Failed to copy code: ', err);
                    });
                }
            });
        });

        // Share Partner
        document.querySelectorAll('.btn-share-partner').forEach(btn => {
            btn.addEventListener('click', function() {
                const title = this.getAttribute('data-title');
                const text = this.getAttribute('data-text');
                const url = this.getAttribute('data-url');

                if (navigator.share) {
                    navigator.share({
                        title: title,
                        text: text,
                        url: url
                    }).catch(err => console.log('Share cancelled or failed', err));
                } else {
                    const shareText = text + ' ' + url;
                    navigator.clipboard.writeText(shareText).then(() => {
                        alert('Share text and link copied to clipboard!');
                    });
                }
            });
        });
    });
</script>
@endsection
