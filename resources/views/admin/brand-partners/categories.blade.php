@extends('admin.layouts.app')

@section('title', 'Brand Partner Categories')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Brand Partner Categories</h1>
    <a href="{{ route('admin.brand-partners.index') }}" class="btn btn-sm btn-outline-secondary">Back to Partners</a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="row g-3">
    <!-- Left Column: Add/Edit Category Form -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm sticky-top" style="top: 20px; z-index: 10;">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0 text-secondary" id="formTitle">Add Category</h6>
            </div>
            <div class="card-body">
                <form id="categoryForm" method="POST" action="{{ route('admin.brand-partners.categories.store') }}">
                    @csrf
                    <input type="hidden" name="_method" id="formMethod" value="POST">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="catName" class="form-control form-control-sm" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Bootstrap Icon Class</label>
                        <input type="text" name="icon" id="catIcon" class="form-control form-control-sm" placeholder="e.g. bi-laptop" maxlength="100">
                        <div class="form-text small">Use a Bootstrap icon class like <code>bi-laptop</code>, <code>bi-book</code> etc.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Theme Color</label>
                        <div class="input-group input-group-sm">
                            <input type="color" name="color" id="catColor" class="form-control form-control-color" style="max-width: 48px; height: 31px;" value="#4A90E2">
                            <input type="text" id="catColorHex" class="form-control form-control-sm" placeholder="#4A90E2" maxlength="7">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="catSortOrder" class="form-control form-control-sm" min="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="catStatus" class="form-select form-select-sm" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1" id="saveButton">Save Category</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="cancelEditButton">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Categories Datatable -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-secondary">Categories List</h6>
                <form method="GET" action="{{ route('admin.brand-partners.categories.index') }}" class="d-flex gap-1" style="max-width: 250px;">
                    <input type="text" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search categories...">
                    <button type="submit" class="btn btn-sm btn-secondary"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;" class="text-center">Order</th>
                            <th>Category</th>
                            <th class="text-center">Color Tag</th>
                            <th>Status</th>
                            <th class="text-end" style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $category)
                            <tr data-id="{{ $category->id }}" data-name="{{ $category->name }}" data-icon="{{ $category->icon }}" data-color="{{ $category->color }}" data-sort="{{ $category->sort_order }}" data-status="{{ $category->status }}">
                                <td class="text-center fw-medium text-muted">{{ $category->sort_order }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded p-2 text-center" style="background-color: {{ $category->color ?? '#999999' }}15; color: {{ $category->color ?? '#666666' }}">
                                            <i class="bi {{ $category->icon ?? 'bi-tag' }} fs-5"></i>
                                        </div>
                                        <div>
                                            <span class="fw-bold text-dark">{{ $category->name }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <code class="px-2 py-1 rounded small text-white border" style="background-color: {{ $category->color ?? '#666666' }}">{{ $category->color ?? '—' }}</code>
                                </td>
                                <td>
                                    <span class="badge {{ $category->status === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }} px-2 py-1">
                                        {{ Str::headline($category->status) }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-category-btn" title="Edit"><i class="bi bi-pencil"></i></button>
                                    @if(auth('admin')->user() && auth('admin')->user()->roles->pluck('key')->contains('global_admin'))
                                        <form method="POST" action="{{ route('admin.brand-partners.categories.destroy', $category) }}" class="d-inline" onsubmit="return confirm('Deleting this category will remove the category association from any connected brand partners. Proceed?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No categories found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($categories->hasPages())
                <div class="card-footer bg-white py-3 border-top">
                    {{ $categories->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('categoryForm');
        const formMethod = document.getElementById('formMethod');
        const formTitle = document.getElementById('formTitle');
        const saveButton = document.getElementById('saveButton');
        const cancelEditButton = document.getElementById('cancelEditButton');
        
        const catName = document.getElementById('catName');
        const catIcon = document.getElementById('catIcon');
        const catColor = document.getElementById('catColor');
        const catColorHex = document.getElementById('catColorHex');
        const catSortOrder = document.getElementById('catSortOrder');
        const catStatus = document.getElementById('catStatus');

        // Keep color inputs synced
        catColor.addEventListener('input', function() {
            catColorHex.value = catColor.value.toUpperCase();
        });
        catColorHex.addEventListener('input', function() {
            if(catColorHex.value.match(/^#[0-9A-F]{6}$/i)) {
                catColor.value = catColorHex.value;
            }
        });

        // Edit button triggers
        document.querySelectorAll('.edit-category-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tr = btn.closest('tr');
                const id = tr.dataset.id;
                const name = tr.dataset.name;
                const icon = tr.dataset.icon;
                const color = tr.dataset.color || '#4A90E2';
                const sort = tr.dataset.sort;
                const status = tr.dataset.status;

                form.action = `/admin/brand-partners/categories/${id}`;
                formMethod.value = 'PUT';
                formTitle.textContent = 'Edit Category: ' + name;
                saveButton.textContent = 'Update Category';
                cancelEditButton.classList.remove('d-none');

                catName.value = name;
                catIcon.value = icon;
                catColor.value = color;
                catColorHex.value = color.toUpperCase();
                catSortOrder.value = sort;
                catStatus.value = status;
            });
        });

        // Cancel edit reset
        cancelEditButton.addEventListener('click', function() {
            form.action = "{{ route('admin.brand-partners.categories.store') }}";
            formMethod.value = 'POST';
            formTitle.textContent = 'Add Category';
            saveButton.textContent = 'Save Category';
            cancelEditButton.classList.add('d-none');
            
            form.reset();
            catColor.value = '#4A90E2';
            catColorHex.value = '#4A90E2';
        });
    });
</script>
@endsection
