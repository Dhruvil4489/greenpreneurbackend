<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom-0 pb-0">
        <ul class="nav nav-tabs card-header-tabs" id="partnerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true">General Information</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="business-tab" data-bs-toggle="tab" data-bs-target="#business" type="button" role="tab" aria-controls="business" aria-selected="false">Business Information</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="offer-tab" data-bs-toggle="tab" data-bs-target="#offer" type="button" role="tab" aria-controls="offer" aria-selected="false">Offer Information</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="display-tab" data-bs-toggle="tab" data-bs-target="#display" type="button" role="tab" aria-controls="display" aria-selected="false">Display &amp; SEO Settings</button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content" id="partnerTabsContent">
            <!-- General Info Tab -->
            <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $partner->name) }}" required maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Slug <span class="text-danger">*</span></label>
                        <input type="text" name="slug" id="slug" class="form-control" value="{{ old('slug', $partner->slug) }}" required placeholder="e.g. brand-name" maxlength="255">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id', $partner->category_id) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Logo</label>
                        <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
                        @if($partner->logo_url)
                            <div class="mt-2">
                                <img src="{{ $partner->logo_url }}" alt="Logo" class="img-thumbnail" style="height:72px; width:auto;">
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cover Image</label>
                        <input type="file" name="cover_image" class="form-control" accept="image/png,image/jpeg,image/webp">
                        @if($partner->cover_image_url)
                            <div class="mt-2">
                                <img src="{{ $partner->cover_image_url }}" alt="Cover" class="img-thumbnail" style="height:72px; width:auto;">
                            </div>
                        @endif
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Short Description</label>
                        <input type="text" name="short_description" class="form-control" value="{{ old('short_description', $partner->short_description) }}" maxlength="500">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Full Description</label>
                        <textarea name="description" class="form-control" rows="5">{{ old('description', $partner->description) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Business Info Tab -->
            <div class="tab-pane fade" id="business" role="tabpanel" aria-labelledby="business-tab">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Website URL</label>
                        <input type="url" name="website" class="form-control" value="{{ old('website', $partner->website) }}" placeholder="https://example.com" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="contact_email" class="form-control" value="{{ old('contact_email', $partner->contact_email) }}" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number (Phone)</label>
                        <input type="text" name="contact_number" class="form-control" value="{{ old('contact_number', $partner->contact_number) }}" maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">WhatsApp Number</label>
                        <input type="text" name="whatsapp" class="form-control" value="{{ old('whatsapp', $partner->whatsapp) }}" placeholder="e.g. +919876543210" maxlength="50">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3">{{ old('address', $partner->address) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Offer Info Tab -->
            <div class="tab-pane fade" id="offer" role="tabpanel" aria-labelledby="offer-tab">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Offer Title</label>
                        <input type="text" name="offer_title" class="form-control" value="{{ old('offer_title', $partner->offer_title) }}" placeholder="e.g. 20% OFF on all plans" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Coupon Code</label>
                        <input type="text" name="coupon_code" class="form-control" value="{{ old('coupon_code', $partner->coupon_code) }}" placeholder="e.g. WELCOME20" maxlength="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Discount Type</label>
                        <select name="discount_type" class="form-select">
                            <option value="">Select Discount Type</option>
                            <option value="percentage" @selected(old('discount_type', $partner->discount_type) == 'percentage')>Percentage (%)</option>
                            <option value="flat" @selected(old('discount_type', $partner->discount_type) == 'flat')>Flat Amount</option>
                            <option value="freebie" @selected(old('discount_type', $partner->discount_type) == 'freebie')>Free Gift / Trial</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Discount Value</label>
                        <input type="number" step="0.01" name="discount_value" class="form-control" value="{{ old('discount_value', $partner->discount_value) }}" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valid From</label>
                        <input type="datetime-local" name="valid_from" class="form-control" value="{{ old('valid_from', $partner->valid_from ? $partner->valid_from->timezone('Asia/Kolkata')->format('Y-m-d\TH:i') : '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Valid To</label>
                        <input type="datetime-local" name="valid_to" class="form-control" value="{{ old('valid_to', $partner->valid_to ? $partner->valid_to->timezone('Asia/Kolkata')->format('Y-m-d\TH:i') : '') }}">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Offer Description</label>
                        <textarea name="offer_description" class="form-control" rows="3">{{ old('offer_description', $partner->offer_description) }}</textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Terms and Conditions</label>
                        <textarea name="terms_and_conditions" class="form-control" rows="3" placeholder="Specify any limits, minimum cart value, or eligibility.">{{ old('terms_and_conditions', $partner->terms_and_conditions) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Display & SEO Tab -->
            <div class="tab-pane fade" id="display" role="tabpanel" aria-labelledby="display-tab">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Display Priority Order</label>
                        <input type="number" name="priority" class="form-control" value="{{ old('priority', $partner->priority ?? 0) }}" min="0">
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $partner->is_active ?? true))>
                            <label class="form-check-label fw-bold" for="is_active">Active &amp; Visible</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" @checked(old('is_featured', $partner->is_featured))>
                            <label class="form-check-label" for="is_featured">Featured Partner</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_sponsored" name="is_sponsored" value="1" @checked(old('is_sponsored', $partner->is_sponsored))>
                            <label class="form-check-label" for="is_sponsored">Sponsored Placement</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_verified" name="is_verified" value="1" @checked(old('is_verified', $partner->is_verified))>
                            <label class="form-check-label" for="is_verified">Verified Brand</label>
                        </div>
                    </div>

                    <div class="col-md-12 border-top pt-3 mt-4">
                        <h6 class="fw-bold text-secondary">Search Engine Optimization (SEO)</h6>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Meta Title</label>
                        <input type="text" name="meta_title" class="form-control" value="{{ old('meta_title', $partner->meta_title) }}" placeholder="Brand name or offer title optimized for search engines" maxlength="255">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Meta Description</label>
                        <textarea name="meta_description" class="form-control" rows="2" maxlength="500" placeholder="Brief SEO description, typically under 160 characters.">{{ old('meta_description', $partner->meta_description) }}</textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Keywords</label>
                        <input type="text" name="keywords" class="form-control" value="{{ old('keywords', $partner->keywords) }}" placeholder="e.g. software, cloud, licensing, discounts" maxlength="255">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const nameInput = document.getElementById('name');
        const slugInput = document.getElementById('slug');

        if(nameInput && slugInput) {
            nameInput.addEventListener('input', function() {
                if(!slugInput.dataset.edited) {
                    slugInput.value = nameInput.value
                        .toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-');
                }
            });

            slugInput.addEventListener('change', function() {
                slugInput.dataset.edited = 'true';
            });
        }
    });
</script>
