<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->title }} - Visitor Registration</title>
    <style>
        :root { color-scheme: light; --brand: #1f5eff; --danger: #b42318; --muted: #667085; --border: #d0d5dd; --success: #067647; }
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; color: #101828; }
        .page { max-width: 980px; margin: 0 auto; padding: 24px; }
        .card { background: #fff; border: 1px solid #eaecf0; border-radius: 18px; box-shadow: 0 10px 24px rgba(16,24,40,.06); padding: 24px; margin-bottom: 20px; }
        h1 { margin: 0 0 8px; font-size: clamp(28px, 4vw, 42px); }
        h2 { margin: 0 0 18px; font-size: 24px; }
        .muted { color: var(--muted); }
        .details { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 18px; }
        .detail { background: #f9fafb; border: 1px solid #eef2f6; border-radius: 12px; padding: 12px; }
        .detail strong { display: block; font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
        form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-weight: 650; margin-bottom: 6px; }
        input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--border); border-radius: 10px; padding: 11px 12px; font: inherit; background: #fff; }
        textarea { min-height: 110px; resize: vertical; }
        .error { color: var(--danger); font-size: 14px; margin-top: 5px; }
        .alert { border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; }
        .alert-error { background: #fffbfa; border: 1px solid #fecdca; color: var(--danger); }
        .alert-success { background: #ecfdf3; border: 1px solid #abefc6; color: var(--success); }
        .button { appearance: none; border: 0; border-radius: 999px; background: var(--brand); color: #fff; font-weight: 750; padding: 13px 22px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .button[disabled] { opacity: .65; cursor: wait; }
        .payment-button { background: #0b7a3b; margin-top: 10px; }
        .qr img { max-width: 180px; border: 1px solid var(--border); border-radius: 12px; padding: 8px; background: #fff; }
        @media (max-width: 720px) { .page { padding: 14px; } form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
@php
    $paymentUrl = $payment['payment_url']
        ?? $payment['checkout_url']
        ?? $payment['zoho_checkout_url']
        ?? $payment['zoho_payment_link_url']
        ?? $payment['zoho_hosted_page_url']
        ?? $registration?->payment_url
        ?? $registration?->zoho_checkout_url
        ?? $registration?->zoho_payment_link_url
        ?? $registration?->zoho_hosted_page_url
        ?? null;
    $paymentStatus = strtolower((string) ($payment['payment_status'] ?? $registration?->payment_status ?? ''));
    $paymentRequired = (bool) ($payment['requires_payment'] ?? false);
@endphp
<div class="page">
    <section class="card">
        <p class="muted">Visitor Registration</p>
        <h1>{{ $event->title }}</h1>
        @if($event->description)
            <p>{{ $event->description }}</p>
        @endif
        <div class="details">
            <div class="detail"><strong>Circle</strong>{{ $event->circle?->name ?? 'Peers Global Unity' }}</div>
            <div class="detail"><strong>Date & time</strong>{{ optional($occurrence->start_at ?? $event->start_at)->format('d M Y, h:i A') ?? 'To be announced' }}@if($occurrence->end_at) - {{ $occurrence->end_at->format('h:i A') }}@endif</div>
            <div class="detail"><strong>Location</strong>{{ $event->location_text ?: ($event->mode === 'online' ? 'Online event' : 'To be announced') }}</div>
            <div class="detail"><strong>Mode</strong>{{ ucfirst((string) ($event->mode ?: ($event->is_virtual ? 'online' : 'offline'))) }}</div>
            @if($event->online_meeting_url)
                <div class="detail"><strong>Meeting link</strong><a href="{{ $event->online_meeting_url }}" target="_blank" rel="noopener">Open meeting details</a></div>
            @endif
            <div class="detail"><strong>Occurrence</strong>{{ $occurrence->occurrence_date ? $occurrence->occurrence_date->format('d M Y') : ('#'.($occurrence->sequence ?? 1)) }}</div>
            @if($event->is_paid || (float) ($event->ticket_price ?? 0) > 0)
                <div class="detail"><strong>Fee</strong>{{ $event->ticket_price }} {{ strtoupper(data_get($event->metadata, 'currency', 'INR')) }}</div>
            @endif
        </div>
        @if($occurrence->metadata)
            <p class="muted">Additional occurrence details are available for this event.</p>
        @endif
    </section>

    @if($registration)
        <section class="card">
            @if($paymentRequired)
                <div class="alert {{ $paymentUrl ? 'alert-success' : 'alert-error' }}">
                    <strong>{{ $paymentUrl ? 'Registration saved. Payment is required.' : 'Registration saved, but payment link is not available yet.' }}</strong>
                    <div>{{ $payment['message'] ?? 'Please complete payment to confirm your registration.' }}</div>
                    <div class="muted">Registration ID: {{ $registration->id }}</div>
                    <div class="muted">Payment status: {{ $payment['payment_status'] ?? $registration->payment_status ?? 'pending' }}</div>
                    @if(isset($payment['amount']) || isset($payment['currency']))
                        <div class="muted">Amount: {{ $payment['amount'] ?? $registration->payment_amount ?? $event->ticket_price }} {{ strtoupper($payment['currency'] ?? data_get($event->metadata, 'currency', 'INR')) }}</div>
                    @endif
                    @if(!empty($payment['error']))
                        <div>{{ $payment['error'] }}</div>
                    @endif
                    @if($paymentUrl)
                        <a class="button payment-button" href="{{ $paymentUrl }}" target="_blank" rel="noopener">Pay now</a>
                    @endif
                </div>
            @else
                <div class="alert alert-success">
                    <strong>{{ in_array($paymentStatus, ['paid', 'success', 'completed'], true) ? 'Payment completed successfully. Your registration is confirmed.' : 'Visitor registered successfully.' }}</strong>
                    <div>Your registration ID is {{ $registration->id }}.</div>
                </div>
                @if($qr)
                    <div class="qr">
                        <h2>Registration / QR details</h2>
                        @if(!empty($qr['qr_code_url']))
                            <img src="{{ $qr['qr_code_url'] }}" alt="Registration QR code">
                            <p><a href="{{ $qr['qr_code_url'] }}" target="_blank" rel="noopener">Open QR code</a></p>
                        @endif
                        @if(!empty($qr['qr_token']))
                            <p><strong>QR token:</strong> {{ $qr['qr_token'] }}</p>
                        @endif
                        <p><strong>Status:</strong> {{ $qr['status'] ?? $registration->status }}</p>
                        <p><strong>Check-in status:</strong> {{ $qr['checkin_status'] ?? $registration->checkin_status }}</p>
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if($unavailableMessage)
        <section class="card">
            <div class="alert alert-error">
                <strong>Registration unavailable</strong>
                <div>{{ $unavailableMessage }}</div>
            </div>
        </section>
    @endif

    @unless($registration || $unavailableMessage)
    <section class="card">
        <h2>Visitor details</h2>
        @if($errors->any())
            <div class="alert alert-error">
                <strong>Please correct the highlighted fields.</strong>
                @if($errors->has('registration'))<div>{{ $errors->first('registration') }}</div>@endif
            </div>
        @endif
        <form id="visitor-registration-form" method="post" action="{{ url('/events/'.$event->id.'/occurrences/'.$occurrence->id.'/visitor-register') }}" novalidate>
            @csrf
            <div>
                <label for="visitor_name">Name *</label>
                <input id="visitor_name" name="visitor_name" value="{{ old('visitor_name') }}" required>
                @error('visitor_name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_email">Email *</label>
                <input id="visitor_email" type="email" name="visitor_email" value="{{ old('visitor_email') }}" required>
                @error('visitor_email')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_phone">Phone *</label>
                <input id="visitor_phone" name="visitor_phone" value="{{ old('visitor_phone') }}" required>
                @error('visitor_phone')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_company">Company *</label>
                <input id="visitor_company" name="visitor_company" value="{{ old('visitor_company') }}" required>
                @error('visitor_company')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_city">City *</label>
                <input id="visitor_city" name="visitor_city" value="{{ old('visitor_city') }}" required>
                @error('visitor_city')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_designation">Designation</label>
                <input id="visitor_designation" name="visitor_designation" value="{{ old('visitor_designation') }}">
                @error('visitor_designation')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_business_category_main_id">Main business category *</label>
                <select id="visitor_business_category_main_id" name="visitor_business_category_main_id" data-selected="{{ old('visitor_business_category_main_id') }}" required>
                    <option value="">Select category</option>
                    @foreach($categories['main'] as $category)
                        <option value="{{ $category->id }}" @selected((string) old('visitor_business_category_main_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('visitor_business_category_main_id')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="visitor_business_category_sub_id">Sub business category *</label>
                <select id="visitor_business_category_sub_id" name="visitor_business_category_sub_id" data-selected="{{ old('visitor_business_category_sub_id') }}" required>
                    <option value="">Select sub category</option>
                    @foreach($categories['sub'] as $category)
                        <option value="{{ $category->id }}" @selected((string) old('visitor_business_category_sub_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('visitor_business_category_sub_id')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="full">
                <label for="visitor_business_website">Business website</label>
                <input id="visitor_business_website" type="url" name="visitor_business_website" value="{{ old('visitor_business_website') }}" placeholder="https://example.com">
                @error('visitor_business_website')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="full">
                <label for="visitor_business_brief">Business brief</label>
                <textarea id="visitor_business_brief" name="visitor_business_brief">{{ old('visitor_business_brief') }}</textarea>
                @error('visitor_business_brief')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="invited_by_type">Invited by *</label>
                <select id="invited_by_type" name="invited_by_type">
                    <option value="peers_global_team" @selected(old('invited_by_type', 'other') === 'peers_global_team')>Peers Global Team</option>
                    <option value="circle_member_peer" @selected(old('invited_by_type', 'other') === 'circle_member_peer')>Circle member / Peer</option>
                    <option value="other" @selected(old('invited_by_type', 'other') === 'other')>Other</option>
                </select>
                @error('invited_by_type')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="invited_by_user_id">Invited by user ID</label>
                <input id="invited_by_user_id" name="invited_by_user_id" value="{{ old('invited_by_user_id') }}" placeholder="UUID if available">
                @error('invited_by_user_id')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="full">
                <button id="submit-button" class="button" type="submit">Submit registration</button>
            </div>
        </form>
    </section>
    @endunless
</div>

<script>
(function () {
    const mainSelect = document.getElementById('visitor_business_category_main_id');
    const subSelect = document.getElementById('visitor_business_category_sub_id');
    const form = document.getElementById('visitor-registration-form');
    const submitButton = document.getElementById('submit-button');
    if (!mainSelect || !subSelect) return;

    const selectedMain = mainSelect.dataset.selected || mainSelect.value;
    const selectedSub = subSelect.dataset.selected || subSelect.value;

    function setOptions(select, items, placeholder, selectedValue) {
        select.innerHTML = '';
        select.appendChild(new Option(placeholder, ''));
        items.forEach((item) => {
            const option = new Option(item.name, item.id);
            option.selected = String(item.id) === String(selectedValue || '');
            select.appendChild(option);
        });
    }

    function itemsFrom(payload, keys) {
        const data = payload && payload.data ? payload.data : payload;
        for (const key of keys) {
            if (Array.isArray(data && data[key])) return data[key];
        }
        return Array.isArray(data && data.items) ? data.items : [];
    }

    async function loadMainCategories() {
        try {
            const response = await fetch('/api/v1/circle-categories', { headers: { 'Accept': 'application/json' } });
            if (!response.ok) return;
            const payload = await response.json();
            const items = itemsFrom(payload, ['items']);
            if (items.length) setOptions(mainSelect, items, 'Select category', selectedMain);
            if (mainSelect.value) await loadSubCategories(mainSelect.value, selectedSub);
        } catch (error) {
            console.warn('Unable to load main categories', error);
        }
    }

    async function loadSubCategories(mainId, selectedValue) {
        if (!mainId) {
            setOptions(subSelect, [], 'Select sub category', null);
            return;
        }
        subSelect.disabled = true;
        try {
            const response = await fetch('/api/v1/circle-categories/' + encodeURIComponent(mainId), { headers: { 'Accept': 'application/json' } });
            if (!response.ok) return;
            const payload = await response.json();
            const items = itemsFrom(payload, ['level4_categories', 'sub_categories', 'children']);
            setOptions(subSelect, items, 'Select sub category', selectedValue);
        } catch (error) {
            console.warn('Unable to load sub categories', error);
        } finally {
            subSelect.disabled = false;
        }
    }

    mainSelect.addEventListener('change', () => loadSubCategories(mainSelect.value, null));
    if (form && submitButton) {
        form.addEventListener('submit', () => {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';
        });
    }
    loadMainCategories();
})();
</script>
</body>
</html>
