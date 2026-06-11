<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" value="{{ old('title', $ad->title) }}" required maxlength="255">
    </div>
    <div class="col-md-6">
        <label class="form-label">Subtitle</label>
        <input type="text" name="subtitle" class="form-control" value="{{ old('subtitle', $ad->subtitle) }}" maxlength="255">
    </div>
    <div class="col-md-6">
        <label class="form-label">Page Name</label>
        <input type="text" name="page_name" class="form-control" value="{{ old('page_name', $ad->page_name) }}" maxlength="100">
    </div>
    <div class="col-md-6 d-flex align-items-end pb-2">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $ad->is_active ?? true))>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label">Redirect URL</label>
        <input type="url" name="redirect_url" class="form-control" value="{{ old('redirect_url', $ad->redirect_url) }}" maxlength="500">
    </div>
    <div class="col-md-6">
        <label class="form-label">Button Text</label>
        <input type="text" name="button_text" class="form-control" value="{{ old('button_text', $ad->button_text) }}" maxlength="100">
    </div>
    <div class="col-md-6">
        <label class="form-label">Starts At</label>
        <input type="datetime-local" name="starts_at" class="form-control" value="{{ old('starts_at', $ad->starts_at ? $ad->starts_at->timezone('Asia/Kolkata')->format('Y-m-d\TH:i') : '') }}">
    </div>
    <div class="col-md-6">
        <label class="form-label">Ends At</label>
        <input type="datetime-local" name="ends_at" class="form-control" value="{{ old('ends_at', $ad->ends_at ? $ad->ends_at->timezone('Asia/Kolkata')->format('Y-m-d\TH:i') : '') }}">
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3">{{ old('description', $ad->description) }}</textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Image</label>
        <input type="file" name="image" class="form-control" accept="image/png,image/jpeg,image/webp">
        @if($ad->image_url)
            <div class="mt-2">
                <img src="{{ $ad->image_url }}" alt="Ad image" class="img-thumbnail" style="height:72px; width:auto;">
            </div>
        @endif
    </div>
</div>
