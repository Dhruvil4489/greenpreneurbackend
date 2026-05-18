@extends('admin.layouts.app')

@section('title', $mode === 'edit' ? 'Edit Campaign' : 'Create Campaign')

@section('content')
    @include('admin.campaigns.partials.flash')
    @php
        $filters = old('filters', $campaign->filters ?: []);
        $campaignType = old('campaign_type', $campaign->campaign_type ?: 'email_only');
        $audienceType = old('audience_type', $campaign->audience_type ?: 'all_members');
        $showEmailFields = in_array($campaignType, ['email_only', 'email_and_notification'], true);
        $showNotificationFields = in_array($campaignType, ['notification_only', 'email_and_notification'], true);
        $selectedBusinessCategoryIds = collect($filters['business_category_ids'] ?? $filters['category_ids'] ?? [])->map(fn ($id) => (string) $id)->all();
        $selectedPamphletId = old('pamphlet_id', $campaign->pamphlet_id ?? '');
        $selectedEmailTemplateId = old('email_template_id', $campaign->email_template_id ?: optional($defaultEmailTemplate)->id);
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ $mode === 'edit' ? 'Edit Campaign' : 'Create Campaign' }}</h1>
        <a href="{{ route('admin.campaigns.index') }}" class="btn btn-outline-secondary">Back</a>
    </div>

    <form id="campaignForm" method="POST" action="{{ $mode === 'edit' ? route('admin.campaigns.update', $campaign) : route('admin.campaigns.store') }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif
        <input type="hidden" name="action" id="campaignAction" value="draft">
        <input type="hidden" name="pamphlet_id" id="pamphletId" value="{{ $selectedPamphletId }}">
        <input type="hidden" name="email_template_id" id="emailTemplateId" value="{{ $selectedEmailTemplateId }}">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Campaign Title</label>
                        <input type="text" name="title" class="form-control" value="{{ old('title', $campaign->title) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Campaign Type</label>
                        <select name="campaign_type" id="campaignType" class="form-select" required>
                            <option value="email_only" @selected($campaignType === 'email_only')>Email Only</option>
                            <option value="notification_only" @selected($campaignType === 'notification_only')>Notification Only</option>
                            <option value="email_and_notification" @selected($campaignType === 'email_and_notification')>Email + Notification</option>
                        </select>
                    </div>
                    <div id="emailFields" class="email-fields {{ $showEmailFields ? '' : 'd-none' }}">
                        <div class="mb-3">
                            <label class="form-label" for="campaignSubject">Subject</label>
                            <input type="text" id="campaignSubject" name="subject" class="form-control" value="{{ old('subject', $campaign->subject ?? '') }}" @required($showEmailFields)>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <label class="form-label mb-0">Choose Email Template</label>
                                    <div class="text-muted small">Basic templates inspired by Zoho Campaigns. Existing email body editing still works.</div>
                                </div>
                                <a href="{{ route('admin.campaign-email-templates.index') }}" class="btn btn-sm btn-outline-secondary">View Templates</a>
                            </div>
                            <div class="row g-3" id="emailTemplateGrid">
                                @foreach ($emailTemplates as $template)
                                    <div class="col-sm-6 col-xl-4">
                                        <div class="card h-100 campaign-email-template-card @if ((string) $selectedEmailTemplateId === (string) $template['id']) selected @endif" role="button" tabindex="0" data-template-id="{{ $template['id'] }}">
                                            <div class="card-body p-3">
                                                <div class="campaign-template-thumb campaign-template-thumb-{{ $template['template_type'] }} mb-3">
                                                    <span></span><span></span><span></span><span></span>
                                                </div>
                                                <h3 class="h6 mb-2">{{ $template['name'] }}</h3>
                                                <button type="button" class="btn btn-sm @if ((string) $selectedEmailTemplateId === (string) $template['id']) btn-primary @else btn-outline-primary @endif w-100 template-select-label">
                                                    @if ((string) $selectedEmailTemplateId === (string) $template['id']) Selected @else Select Template @endif
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" for="campaignEmailBody">Email Body</label>
                                <button type="button" class="btn btn-sm btn-outline-primary select-pamphlet-btn" data-target="email">Select Pamphlet</button>
                            </div>
                            <textarea id="campaignEmailBody" name="email_body" rows="10" class="form-control" placeholder="HTML content is supported" @required($showEmailFields)>{{ old('email_body', $campaign->email_body ?? '') }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Live Email Template Preview</label>
                            <div class="campaign-email-preview-shell">
                                <div class="campaign-email-preview-header">Peers Global Wrapper Preview</div>
                                <div id="emailTemplatePreview" class="campaign-email-preview-body"></div>
                            </div>
                        </div>
                    </div>
                    <div id="notificationFields" class="notification-fields {{ $showNotificationFields ? '' : 'd-none' }}">
                        <div class="mb-3">
                            <label class="form-label" for="campaignNotificationTitle">Notification Title</label>
                            <input type="text" id="campaignNotificationTitle" name="notification_title" class="form-control" value="{{ old('notification_title', $campaign->notification_title ?? '') }}" @required($showNotificationFields)>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" for="campaignNotificationMessage">Notification Message</label>
                                <button type="button" class="btn btn-sm btn-outline-primary select-pamphlet-btn" data-target="notification">Select Pamphlet</button>
                            </div>
                            <textarea id="campaignNotificationMessage" name="notification_message" rows="4" class="form-control" @required($showNotificationFields)>{{ old('notification_message', $campaign->notification_message ?? '') }}</textarea>
                        </div>
                    </div>
                </div></div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-3"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Audience Selection</h2>
                        <button type="button" id="importAudienceBtn" class="btn btn-sm btn-outline-primary">Import</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Audience</label>
                        <select name="audience_type" id="audienceType" class="form-select" required>
                            <option value="all_members" @selected($audienceType === 'all_members')>All Members</option>
                            <option value="city" @selected($audienceType === 'city')>City Wise</option>
                            <option value="circle" @selected($audienceType === 'circle')>Circle Wise</option>
                            <option value="company" @selected($audienceType === 'company')>Company Wise</option>
                            <option value="category" @selected($audienceType === 'category')>Business Category Wise</option>
                            <option value="membership_status" @selected($audienceType === 'membership_status')>Membership Status Wise</option>
                            <option value="specific_members" @selected($audienceType === 'specific_members')>Specific Members</option>
                            <option value="custom_filter" @selected($audienceType === 'custom_filter')>Custom Filter</option>
                        </select>
                    </div>

                    @foreach ([['cities','City Wise',$filterOptions['cities']], ['companies','Company Wise',$filterOptions['companies']], ['membership_statuses','Membership Status Wise',$filterOptions['membership_statuses']]] as [$key,$label,$options])
                        <div class="filter-block" data-filter="{{ $key }}">
                            <label class="form-label">{{ $label }}</label>
                            <select name="filters[{{ $key }}][]" class="form-select select2" multiple>
                                @foreach ($options as $option)
                                    <option value="{{ $option }}" @selected(in_array($option, $filters[$key] ?? [], true))>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    <div class="filter-block" data-filter="circle_ids">
                        <label class="form-label">Circle Wise</label>
                        <select name="filters[circle_ids][]" class="form-select select2" multiple>
                            @foreach ($filterOptions['circles'] as $circle)
                                <option value="{{ $circle['id'] }}" @selected(in_array($circle['id'], $filters['circle_ids'] ?? [], true))>{{ $circle['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="filter-block" data-filter="business_category_ids">
                        <label class="form-label">Business Category Wise</label>
                        <select name="filters[business_category_ids][]" class="form-select select2" multiple>
                            @foreach ($filterOptions['categories'] as $category)
                                <option value="{{ $category['id'] }}" @selected(in_array((string) $category['id'], $selectedBusinessCategoryIds, true))>{{ $category['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="filter-block" data-filter="user_ids">
                        <label class="form-label">Specific Members</label>
                        <select name="filters[user_ids][]" id="memberSelect" class="form-select select2" multiple>
                            @foreach (($filters['user_ids'] ?? []) as $userId)
                                <option value="{{ $userId }}" selected>{{ $userId }}</option>
                            @endforeach
                        </select>
                    </div>
                </div></div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('campaignAction').value='draft'">Save Draft</button>
                    <button type="button" id="previewRecipientsBtn" class="btn btn-outline-secondary">Preview Recipients</button>
                    <button type="submit" class="btn btn-success" onclick="document.getElementById('campaignAction').value='send'; return confirm('Send this campaign now? This cannot be undone.');">Send Campaign</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm mt-4" id="previewCard" style="display:none;">
        <div class="card-header d-flex justify-content-between"><strong>Preview Recipients</strong><span>Total: <span id="previewTotal">0</span></span></div>
        <div id="previewDebug" class="small text-muted px-3 py-2 border-bottom" style="display:none;"></div>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>City</th><th>Company</th><th>Membership</th><th>Circle</th></tr></thead><tbody id="previewBody"></tbody></table></div>
    </div>

    <div class="modal fade" id="audienceImportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Import Audience Values</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="audienceImportAlert" class="alert d-none" role="alert"></div>
                <p class="text-muted small mb-3">Upload a CSV/XLSX/XLS file. Columns are detected automatically based on the selected audience type and imported values fill the current audience fields only.</p>
                <div class="mb-3">
                    <label class="form-label" for="audienceImportFile">Audience File</label>
                    <input type="file" id="audienceImportFile" class="form-control" accept=".csv,.xlsx,.xls" required>
                    <div class="form-text">Maximum file size: 10 MB.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Detected Columns</label>
                    <div id="audienceImportColumns" class="border rounded p-2 text-muted small">Upload a file to preview detected columns.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Download Sample CSVs</label>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.campaigns.audience-samples', 'city') }}" class="btn btn-sm btn-outline-secondary">Download City Sample</a>
                        <a href="{{ route('admin.campaigns.audience-samples', 'company') }}" class="btn btn-sm btn-outline-secondary">Download Company Sample</a>
                        <a href="{{ route('admin.campaigns.audience-samples', 'membership_status') }}" class="btn btn-sm btn-outline-secondary">Download Membership Status Sample</a>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="audienceImportSubmit" class="btn btn-primary">Import</button>
            </div>
        </div></div>
    </div>

    <div class="modal fade" id="pamphletSelectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Select Pamphlet</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div id="pamphletList" class="row g-3"></div></div>
            <div class="modal-footer"><a href="{{ route('admin.campaign-pamphlets.create') }}" class="btn btn-outline-primary">Add Pamphlet</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>
@endsection


@push('styles')
<style>
.campaign-email-template-card{border:1px solid #e5e7eb;transition:all .18s ease;cursor:pointer}.campaign-email-template-card:hover{transform:translateY(-2px);box-shadow:0 .5rem 1rem rgba(15,23,42,.12);border-color:#8b5cf6}.campaign-email-template-card.selected{border:2px solid #240e5c;box-shadow:0 0 0 .2rem rgba(36,14,92,.12)}.campaign-template-thumb{height:132px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;padding:12px;display:grid;gap:7px}.campaign-template-thumb span{display:block;border-radius:8px;background:#dbeafe;border:1px solid #bfdbfe}.campaign-template-thumb-simple_text{grid-template-rows:repeat(4,1fr)}.campaign-template-thumb-single_column{grid-template-rows:2fr 1fr 1fr}.campaign-template-thumb-one_two_column,.campaign-template-thumb-one_two_column_alternate{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1.5fr}.campaign-template-thumb-one_two_column span:first-child,.campaign-template-thumb-one_two_column_alternate span:first-child{grid-column:1/3}.campaign-template-thumb-one_two_one_two_column{grid-template-columns:1fr 1fr;grid-template-rows:.8fr 1fr .8fr 1fr}.campaign-template-thumb-one_two_one_two_column span:first-child,.campaign-template-thumb-one_two_one_two_column span:nth-child(4){grid-column:1/3}.campaign-template-thumb-one_three_column{grid-template-columns:repeat(3,1fr);grid-template-rows:1fr 1.5fr}.campaign-template-thumb-one_three_column span:first-child{grid-column:1/4}.campaign-template-thumb-blank span{display:none}.campaign-email-preview-shell{border:1px solid #dbe3ef;border-radius:14px;background:#f4f4f4;overflow:hidden}.campaign-email-preview-header{background:#240e5c;color:#fff;text-align:center;font-size:12px;font-weight:700;padding:8px}.campaign-email-preview-body{background:#fff;margin:16px;padding:18px;border-radius:10px;min-height:150px;overflow:auto}
</style>
@endpush

@push('scripts')
<script>
(function () {
    const campaignType = document.getElementById('campaignType');
    const audienceType = document.getElementById('audienceType');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const filterOptions = @json($filterOptions);
    const emailTemplates = @json($emailTemplates);
    let pamphlets = [];
    let pamphletTarget = 'both';
    let currentPamphletImageUrl = @json(data_get($campaign->pamphlet_snapshot ?? [], 'image_url', ''));

    $('.select2').select2({ width: '100%' });
    $('#memberSelect').select2({
        width: '100%',
        ajax: {
            url: '{{ route('admin.campaigns.member-search') }}',
            dataType: 'json',
            delay: 250,
            data: params => ({ search: params.term || '' }),
            processResults: data => ({ results: (data.items || []).map(item => ({ id: item.id, text: `${item.display_name} (${item.email || item.phone || 'No contact'})` })) })
        }
    });

    function setSectionVisibility(section, visible, requiredFieldNames) {
        section.classList.toggle('d-none', !visible);
        section.querySelectorAll('input, textarea, select').forEach((field) => {
            field.required = visible && requiredFieldNames.includes(field.name);
        });
    }

    function syncTypeFields() {
        const type = campaignType.value;
        const emailVisible = type === 'email_only' || type === 'email_and_notification';
        const notificationVisible = type === 'notification_only' || type === 'email_and_notification';

        setSectionVisibility(document.getElementById('emailFields'), emailVisible, ['subject', 'email_body']);
        setSectionVisibility(document.getElementById('notificationFields'), notificationVisible, ['notification_title', 'notification_message']);
    }

    function syncFilterFields() {
        const type = audienceType.value;
        const visible = {
            city: ['cities'], circle: ['circle_ids'], company: ['companies'], category: ['business_category_ids'], membership_status: ['membership_statuses'], specific_members: ['user_ids'],
            custom_filter: ['cities','circle_ids','companies','business_category_ids','membership_statuses','user_ids']
        }[type] || [];
        document.querySelectorAll('.filter-block').forEach(el => el.style.display = visible.includes(el.dataset.filter) ? 'block' : 'none');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    }

    function selectForFilter(filterKey) {
        return document.querySelector(`.filter-block[data-filter="${filterKey}"] select`);
    }

    function addSelectValue(select, value, label) {
        if (!select) return;
        const stringValue = String(value);
        if (!Array.from(select.options).some(option => option.value === stringValue)) {
            select.add(new Option(label || stringValue, stringValue, true, true));
        }
        const current = $(select).val() || [];
        if (!current.includes(stringValue)) current.push(stringValue);
        $(select).val(current).trigger('change');
    }

    function selectedEmailTemplate() {
        const selectedId = document.getElementById('emailTemplateId').value;
        return emailTemplates.find(template => String(template.id) === String(selectedId)) || emailTemplates.find(template => template.slug === 'simple-text') || emailTemplates[0] || null;
    }

    function splitContent(content, parts) {
        const blocks = content.split(/(?=<p\b|<h[1-6]\b|<ul\b|<ol\b|<div\b)/i).filter(Boolean);
        const chunks = Array.from({ length: parts }, () => '');
        (blocks.length ? blocks : [content]).forEach((block, index) => { chunks[index % parts] += block; });
        return chunks.map(chunk => chunk.trim());
    }

    function renderTemplateHtml(template, content, imageUrl = '') {
        if (!template) return content || '<p>Add your campaign content here.</p>';
        const safeContent = content && content.trim() ? content : '<p>Add your campaign content here.</p>';
        const imageHtml = imageUrl
            ? `<img src="${escapeHtml(imageUrl)}" alt="Campaign image" style="max-width:100%;height:auto;border-radius:12px;display:block;">`
            : '<div style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;color:#64748b;">Image / visual block</div>';
        const two = splitContent(safeContent, 2);
        const three = splitContent(safeContent, 3);
        let html = template.html_structure || '@{{content}}';
        const replacements = {
            '@{{content}}': safeContent,
            '@{{image}}': imageHtml,
            '@{{content_left}}': two[0] || safeContent,
            '@{{content_right}}': two[1] || safeContent,
            '@{{card_1}}': three[0] || safeContent,
            '@{{card_2}}': three[1] || three[0] || safeContent,
            '@{{card_3}}': three[2] || three[0] || safeContent,
        };
        Object.entries(replacements).forEach(([token, value]) => { html = html.split(token).join(value); });
        return `${template.css_styles ? `<style>${template.css_styles}</style>` : ''}${html}`;
    }

    function renderEmailTemplatePreview() {
        const preview = document.getElementById('emailTemplatePreview');
        if (!preview) return;
        preview.innerHTML = renderTemplateHtml(selectedEmailTemplate(), document.getElementById('campaignEmailBody').value, currentPamphletImageUrl);
    }

    function selectEmailTemplate(templateId) {
        document.getElementById('emailTemplateId').value = templateId || '';
        document.querySelectorAll('.campaign-email-template-card').forEach(card => {
            const selected = String(card.dataset.templateId) === String(templateId);
            card.classList.toggle('selected', selected);
            const button = card.querySelector('.template-select-label');
            if (button) {
                button.textContent = selected ? 'Selected' : 'Select Template';
                button.classList.toggle('btn-primary', selected);
                button.classList.toggle('btn-outline-primary', !selected);
            }
        });
        renderEmailTemplatePreview();
    }

    document.querySelectorAll('.campaign-email-template-card').forEach(card => {
        card.addEventListener('click', () => selectEmailTemplate(card.dataset.templateId));
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectEmailTemplate(card.dataset.templateId);
            }
        });
    });

    document.getElementById('campaignEmailBody').addEventListener('input', renderEmailTemplatePreview);

    function showAudienceImportAlert(message, type = 'success') {
        const alert = document.getElementById('audienceImportAlert');
        alert.className = `alert alert-${type}`;
        alert.textContent = message;
    }

    function resetAudienceImportModal() {
        document.getElementById('audienceImportFile').value = '';
        document.getElementById('audienceImportColumns').innerHTML = '<span class="text-muted">Upload a file to preview detected columns.</span>';
        document.getElementById('audienceImportAlert').className = 'alert d-none';
        document.getElementById('audienceImportAlert').textContent = '';
    }

    function fillImportedFilters(filters) {
        Object.entries(filters || {}).forEach(([filterKey, payload]) => {
            const select = selectForFilter(filterKey);
            (payload.options || []).forEach(option => addSelectValue(select, option.value, option.label));
        });
    }

    function renderDetectedColumns(columns, matchedColumns = {}) {
        const container = document.getElementById('audienceImportColumns');
        const badges = (columns || []).map(column => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(column)}</span>`).join('');
        const matched = Object.entries(matchedColumns || {}).map(([filter, cols]) => `<div class="small mt-1"><strong>${escapeHtml(filter)}:</strong> ${cols.map(escapeHtml).join(', ')}</div>`).join('');
        container.innerHTML = badges || '<span class="text-muted">No columns detected.</span>';
        if (matched) container.innerHTML += `<div class="mt-2">${matched}</div>`;
    }

    document.getElementById('importAudienceBtn').addEventListener('click', () => {
        resetAudienceImportModal();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('audienceImportModal')).show();
    });

    document.getElementById('audienceImportSubmit').addEventListener('click', async () => {
        const fileInput = document.getElementById('audienceImportFile');
        if (!fileInput.files.length) {
            showAudienceImportAlert('Please choose a CSV, XLSX, or XLS file to import.', 'warning');
            return;
        }

        const button = document.getElementById('audienceImportSubmit');
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('audience_type', audienceType.value);

        button.disabled = true;
        button.textContent = 'Importing...';
        try {
            const response = await fetch('{{ route('admin.campaigns.import-audience') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: formData
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                showAudienceImportAlert(data.message || 'Audience import failed.', 'danger');
                return;
            }

            renderDetectedColumns(data.data.columns || [], data.data.matched_columns || {});
            fillImportedFilters(data.data.filters || { [data.data.filter]: { options: (data.data.values || []).map(value => ({ value, label: (data.data.labels || {})[value] || value })) } });
            showAudienceImportAlert(data.message || `${data.data.count || 0} values imported successfully.`, 'success');
        } catch (error) {
            showAudienceImportAlert('Audience import failed. Please check the file and try again.', 'danger');
        } finally {
            button.disabled = false;
            button.textContent = 'Import';
        }
    });

    function pamphletImageHtml(url) {
        return url ? `<p><img src="${url}" style="max-width:100%;height:auto;"></p>` : '';
    }

    function applyPamphlet(pamphlet, target = 'both') {
        const type = campaignType.value;
        document.getElementById('pamphletId').value = pamphlet.id;
        if ((target === 'email' || target === 'both') && (type === 'email_only' || type === 'email_and_notification')) {
            currentPamphletImageUrl = pamphlet.image_url || '';
            const template = selectedEmailTemplate();
            const appendImageToBody = !template || ['blank-template', 'simple-text'].includes(template.slug);
            document.getElementById('campaignEmailBody').value = `${pamphlet.content || ''}${appendImageToBody ? pamphletImageHtml(pamphlet.image_url || '') : ''}`;
            renderEmailTemplatePreview();
        }
        if ((target === 'notification' || target === 'both') && (type === 'notification_only' || type === 'email_and_notification')) {
            document.getElementById('campaignNotificationMessage').value = pamphlet.short_message || pamphlet.title || '';
        }
    }

    async function loadPamphlets() {
        if (pamphlets.length) return pamphlets;
        const response = await fetch('{{ route('admin.campaign-pamphlets.select-list') }}', { headers: { 'Accept': 'application/json' } });
        pamphlets = await response.json();
        return pamphlets;
    }

    async function renderPamphlets() {
        const items = await loadPamphlets();
        const list = document.getElementById('pamphletList');
        list.innerHTML = items.map(item => `
            <div class="col-md-6">
                <div class="card h-100">
                    ${item.image_url ? `<img src="${escapeHtml(item.image_url)}" class="card-img-top" style="height:140px;object-fit:cover;" alt="${escapeHtml(item.title)}">` : ''}
                    <div class="card-body">
                        <h6 class="card-title">${escapeHtml(item.title)}</h6>
                        <p class="small text-muted">${escapeHtml(item.short_message || '')}</p>
                        <button type="button" class="btn btn-sm btn-primary pamphlet-choose" data-id="${item.id}">Select</button>
                    </div>
                </div>
            </div>`).join('') || '<div class="col-12 text-muted">No active pamphlets found.</div>';
    }

    document.querySelectorAll('.select-pamphlet-btn').forEach(button => {
        button.addEventListener('click', async () => {
            pamphletTarget = button.dataset.target || 'both';
            await renderPamphlets();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('pamphletSelectModal')).show();
        });
    });

    document.getElementById('pamphletList').addEventListener('click', (event) => {
        const button = event.target.closest('.pamphlet-choose');
        if (!button) return;
        const pamphlet = pamphlets.find(item => item.id === button.dataset.id);
        if (!pamphlet) return;
        applyPamphlet(pamphlet, campaignType.value === 'email_and_notification' ? 'both' : pamphletTarget);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pamphletSelectModal')).hide();
    });

    campaignType.addEventListener('change', syncTypeFields);
    audienceType.addEventListener('change', syncFilterFields);
    syncTypeFields(); syncFilterFields(); renderEmailTemplatePreview();

    document.getElementById('previewRecipientsBtn').addEventListener('click', async () => {
        const formData = new FormData(document.getElementById('campaignForm'));
        const response = await fetch('{{ route('admin.campaigns.preview-recipients') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: formData });
        const data = await response.json();
        if (!response.ok) { alert(data.message || 'Preview failed.'); return; }
        document.getElementById('previewTotal').textContent = data.total;
        const debug = data.debug || {};
        const selectedCategoryIds = debug.selected_business_category_ids || [];
        const debugEl = document.getElementById('previewDebug');
        if (selectedCategoryIds.length) {
            debugEl.textContent = `Selected category id(s): ${selectedCategoryIds.join(', ')} | Matched users: ${debug.matched_users_count ?? data.total}`;
            debugEl.style.display = 'block';
        } else {
            debugEl.textContent = '';
            debugEl.style.display = 'none';
        }
        document.getElementById('previewBody').innerHTML = (data.recipients || []).map(row => `<tr><td>${row.display_name || '-'}</td><td>${row.email || '-'}</td><td>${row.phone || '-'}</td><td>${row.city || '-'}</td><td>${row.company_name || '-'}</td><td>${row.membership_status || '-'}</td><td>${row.circle_name || '-'}</td></tr>`).join('') || '<tr><td colspan="7" class="text-center text-muted">No recipients found.</td></tr>';
        document.getElementById('previewCard').style.display = 'block';
    });
})();
</script>
@endpush
