@extends('admin.layouts.app')

@section('title', 'Add Peer')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">Add Peer</h5>
        <small class="text-muted">Create a new platform member</small>
    </div>
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary btn-sm">Back to Peers</a>
</div>

@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <strong>There were some problems with your input.</strong>
        <ul class="mb-0 small">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form id="userCreateForm" action="{{ route('admin.users.store') }}" method="POST">
    @csrf

    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Basic Info</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="{{ old('first_name', $user->first_name) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="{{ old('last_name', $user->last_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Display Name</label>
                        <input type="text" name="display_name" class="form-control" value="{{ old('display_name', $user->display_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Designation</label>
                        <input type="text" name="designation" class="form-control" value="{{ old('designation', $user->designation) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Business & Profile</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $user->company_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Business Type</label>
                        <input type="text" name="business_type" class="form-control" value="{{ old('business_type', $user->business_type) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Turnover Range</label>
                        <input type="text" name="turnover_range" class="form-control" value="{{ old('turnover_range', $user->turnover_range) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender</label>
                        <input type="text" name="gender" class="form-control" value="{{ old('gender', $user->gender) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="dob" class="form-control" value="{{ old('dob', optional($user->dob)->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Experience Years</label>
                        <input type="number" name="experience_years" class="form-control" min="0" max="100" value="{{ old('experience_years', $user->experience_years) }}">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Experience Summary</label>
                        <textarea name="experience_summary" class="form-control" rows="2">{{ old('experience_summary', $user->experience_summary) }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Short Bio</label>
                        <textarea name="short_bio" class="form-control" rows="2">{{ old('short_bio', $user->short_bio) }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Profile Photo</label>
                        <input type="hidden" name="profile_photo_file_id" id="profilePhotoFileId" value="{{ old('profile_photo_file_id', $user->profile_photo_file_id) }}">
                        <div id="profilePhotoExisting" class="d-none">
                            <div class="d-flex align-items-center gap-2">
                                <a href="#" target="_blank" class="btn btn-outline-secondary btn-sm">View Image</a>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-change-target="profilePhoto">Change</button>
                            </div>
                        </div>
                        <div id="profilePhotoUpload">
                            <input type="file" class="form-control" id="profilePhotoFile" accept="image/*">
                            <div class="form-text" id="profilePhotoStatus">Upload up to 10MB.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cover Photo</label>
                        <input type="hidden" name="cover_photo_file_id" id="coverPhotoFileId" value="{{ old('cover_photo_file_id', $user->cover_photo_file_id) }}">
                        <div id="coverPhotoExisting" class="d-none">
                            <div class="d-flex align-items-center gap-2">
                                <a href="#" target="_blank" class="btn btn-outline-secondary btn-sm">View Image</a>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-change-target="coverPhoto">Change</button>
                            </div>
                        </div>
                        <div id="coverPhotoUpload">
                            <input type="file" class="form-control" id="coverPhotoFile" accept="image/*">
                            <div class="form-text" id="coverPhotoStatus">Upload up to 10MB.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Public Profile Slug</label>
                        <input type="text" name="public_profile_slug" class="form-control" value="{{ old('public_profile_slug', $user->public_profile_slug) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Membership & Coins</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Membership Status</label>
                        @php
                            $membershipStatusLabels = [
                                'free_trial_peer' => 'Free Trial Peer',
                                'free_peer' => 'Free Peer',
                                'only_unity_peer' => 'Only Unity Peer',
                                'Only Unity Peer' => 'Only Unity Peer',
                                'Circle Peer' => 'Circle Peer',
                                'Multi Circle Peer' => 'Multi Circle Peer',
                                'Charter Peer' => 'Charter Peer',
                                'Industry Advisor' => 'Industry Advisor',
                                'Charter Investor' => 'Charter Investor',
                                'Circle Founder' => 'Circle Founder',
                                'Circle Director' => 'Circle Director',
                                'Board Advisor' => 'Board Advisor',
                            ];
                        @endphp
                        <select name="membership_status" class="form-select">
                            @foreach ($membershipStatuses as $status)
                                <option value="{{ $status }}" @selected(old('membership_status', $user->membership_status) === $status)>{{ $membershipStatusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Membership Expiry</label>
                        <input type="datetime-local" name="membership_expiry" class="form-control" value="{{ old('membership_expiry', optional($user->membership_ends_at)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Coins Balance</label>
                        <input type="number" name="coins_balance" class="form-control" min="0" value="{{ old('coins_balance', $user->coins_balance ?? 0) }}">
                    </div>
                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" id="isSponsoredMember" name="is_sponsored_member" @checked(old('is_sponsored_member', $user->is_sponsored_member))>
                            <label class="form-check-label" for="isSponsoredMember">
                                Is Sponsored Member
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Membership & Circle Details</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <small class="text-muted">Manual admin override only. Does not affect payment history. Expired membership will be treated as Free Peer.</small>
                    </div>
                    <div class="col-12"><h6 class="mb-0">Membership Details</h6></div>
                    <div class="col-md-4">
                        <label class="form-label">Membership Start Date</label>
                        <input type="date" name="membership_starts_at" class="form-control" value="{{ old('membership_starts_at', optional($user->membership_starts_at)->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Membership Expiry Date</label>
                        <input type="date" name="membership_ends_at" class="form-control" value="{{ old('membership_ends_at', optional($user->membership_ends_at)->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Membership Plan</label>
                        <select name="zoho_plan_code" class="form-select @error('zoho_plan_code') is-invalid @enderror">
                            <option value="">Select Membership Plan</option>
                            @foreach ($membershipPlanOptions as $plan)
                                <option value="{{ $plan['code'] }}" @selected(old('zoho_plan_code', $user->zoho_plan_code) === $plan['code'])>{{ $plan['label'] }}</option>
                            @endforeach
                        </select>
                        @error('zoho_plan_code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Membership plan list is loaded from existing system plans.</div>
                    </div>
                    <div class="col-12"><h6 class="mb-0 mt-2">Circle Membership Details</h6></div>
                    <div class="col-md-4">
                        <label class="form-label" for="active_circle_id">Add Another Circle Membership</label>
                        <select name="active_circle_id" id="active_circle_id" class="form-select @error('active_circle_id') is-invalid @enderror">
                            <option value="">-- No Circle --</option>
                            @foreach ($circles as $circle)
                                <option
                                    value="{{ $circle->id }}"
                                    @selected(old('active_circle_id', old('circle_id')) === $circle->id)
                                >{{ $circle->name }}</option>
                            @endforeach
                        </select>
                        @error('active_circle_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Circle Joined Date</label>
                        <input type="date" name="circle_joined_at" class="form-control" value="{{ old('circle_joined_at', optional($user->circle_joined_at)->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Circle Expiry Date</label>
                        <input type="date" name="circle_expires_at" class="form-control" value="{{ old('circle_expires_at', optional($user->circle_expires_at)->format('Y-m-d')) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Location</div>
                <div class="card-body row g-3">
                    <div class="col-md-12">
                        <label class="form-label">City (string fallback)</label>
                        <input type="text" name="city" class="form-control" value="{{ old('city', $user->city) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Additional Details</div>
                <div class="card-body row g-3">
                    @php
                        $jsonFields = [
                            'industry_tags' => $user->industry_tags,
                            'target_regions' => $user->target_regions,
                            'target_business_categories' => $user->target_business_categories,
                            'hobbies_interests' => $user->hobbies_interests,
                            'leadership_roles' => $user->leadership_roles,
                            'special_recognitions' => $user->special_recognitions,
                            'skills' => $user->skills,
                            'interests' => $user->interests,
                        ];

                        $asCsv = function ($value): string {
                            if (is_array($value)) {
                                return implode(', ', $value);
                            }
                            return '';
                        };

                        $socialLinksValue = '';
                        if (is_array($user->social_links) && $user->social_links !== []) {
                            if (array_keys($user->social_links) !== range(0, count($user->social_links) - 1)) {
                                $pairs = [];
                                foreach ($user->social_links as $k => $v) {
                                    $pairs[] = $k . '=' . $v;
                                }
                                $socialLinksValue = implode(', ', $pairs);
                            } else {
                                $socialLinksValue = implode(', ', $user->social_links);
                            }
                        }
                    @endphp
                    @foreach ($jsonFields as $field => $value)
                        <div class="col-md-6">
                            <label class="form-label text-capitalize">{{ str_replace('_', ' ', $field) }}</label>
                            <textarea name="{{ $field }}" class="form-control" rows="3" placeholder="Enter comma separated values (e.g. IT, Finance, Retail)">{{ old($field, $asCsv($value)) }}</textarea>
                        </div>
                    @endforeach
                    <div class="col-md-6">
                        <label class="form-label">Social Links</label>
                        <textarea name="social_links" class="form-control" rows="3" placeholder="Enter social links">{{ old('social_links', $socialLinksValue) }}</textarea>
                        <small class="text-muted">
                            Enter comma separated links, optionally as key=value
                            (e.g. linkedin=https://linkedin.com/..., website=https://example.com)
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const uploadUrl = '{{ route('admin.files.upload') }}';

        const setupUploader = (prefix) => {
            const fileInput = document.getElementById(`${prefix}File`);
            const hiddenInput = document.getElementById(`${prefix}FileId`);
            const existing = document.getElementById(`${prefix}Existing`);
            const upload = document.getElementById(`${prefix}Upload`);
            const status = document.getElementById(`${prefix}Status`);
            const changeBtn = existing?.querySelector('[data-change-target]');
            const viewLink = existing?.querySelector('a');

            const setStatus = (text, isError = false) => {
                if (!status) return;
                status.textContent = text;
                status.classList.toggle('text-danger', isError);
            };

            changeBtn?.addEventListener('click', () => {
                if (existing) existing.classList.add('d-none');
                if (upload) upload.classList.remove('d-none');
                if (hiddenInput) hiddenInput.value = '';
                if (fileInput) fileInput.value = '';
                setStatus('Select a file to upload.');
            });

            fileInput?.addEventListener('change', async () => {
                const file = fileInput.files?.[0];
                if (!file) return;
                setStatus('Uploading...');

                const formData = new FormData();
                formData.append('file', file);

                try {
                    const response = await fetch(uploadUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {},
                    });

                    if (!response.ok) {
                        setStatus('Upload failed. Please try again.', true);
                        return;
                    }

                    const json = await response.json();
                    const fileId = json?.data?.id ?? json?.data?.[0]?.id;
                    if (!fileId) {
                        setStatus('Upload failed. Missing file id.', true);
                        return;
                    }

                    if (hiddenInput) hiddenInput.value = fileId;
                    if (viewLink) {
                        viewLink.href = `/api/v1/files/${fileId}`;
                        existing?.classList.remove('d-none');
                    }

                    if (upload) upload.classList.add('d-none');
                    setStatus('Upload successful.');
                } catch (e) {
                    setStatus('Upload failed. Please try again.', true);
                }
            });
        };

        setupUploader('profilePhoto');
        setupUploader('coverPhoto');

    });
</script>
@endpush
