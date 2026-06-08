@extends('admin.layouts.app')

@section('title', 'All Posts')

@section('content')
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form id="postsFiltersForm" method="GET" action="{{ route('admin.posts.index') }}"></form>

    <div class="card shadow-sm">
        <div class="p-3 border-bottom">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label small text-muted">Active</label>
                    <select name="active" form="postsFiltersForm" class="form-select form-select-sm">
                        <option value="all" @selected(($filters['active'] ?? 'all') === 'all')>All</option>
                        <option value="active" @selected(($filters['active'] ?? '') === 'active')>Active</option>
                        <option value="deactivated" @selected(($filters['active'] ?? '') === 'deactivated')>Deactivated</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small text-muted">Visibility</label>
                    <select name="visibility" form="postsFiltersForm" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($visibilities as $visibility)
                            <option value="{{ $visibility }}" @selected(($filters['visibility'] ?? '') === $visibility)>{{ ucfirst($visibility) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small text-muted">Moderation Status</label>
                    <select name="moderation_status" form="postsFiltersForm" class="form-select form-select-sm">
                        @foreach ($moderationOptions as $value => $label)
                            <option value="{{ $value === 'any' ? '' : $value }}" @selected(($filters['moderation_status'] ?? '') === ($value === 'any' ? '' : $value))>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small text-muted">Circle</label>
                    <select name="circle_id" form="postsFiltersForm" class="form-select form-select-sm">
                        <option value="all">All Circles</option>
                        @foreach ($circles as $c)
                            <option value="{{ $c->id }}" @selected(($circleId ?? 'all') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" name="search" form="postsFiltersForm" class="form-control form-control-sm" placeholder="Content or owner" value="{{ $filters['search'] ?? '' }}">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" form="postsFiltersForm" class="btn btn-sm btn-primary">Apply</button>
                    <a href="{{ route('admin.posts.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0 align-middle text-nowrap">
                <thead class="table-light">
                    <tr>
                        <th>Created At</th>
                        <th>Peer Name</th>
                        <th>Visibility</th>
                        <th>Moderation Status</th>
                        <th>Active?</th>
                        <th>Content</th>
                        <th>Media</th>
                        <th>Actions</th>
                    </tr>
                    <tr class="bg-light">
                        <th></th>
                        <th><input type="text" name="peer" form="postsFiltersForm" class="form-control form-control-sm" style="min-width:180px" value="{{ $peer ?? '' }}" placeholder="Peer/Company/City"></th>
                        <th>
                            <select name="inline_visibility" form="postsFiltersForm" class="form-select form-select-sm">
                                <option value="any">Any</option>
                                @foreach ($visibilities as $visibility)
                                    <option value="{{ $visibility }}" @selected(($inlineVisibility ?? 'any') === $visibility)>{{ ucfirst($visibility) }}</option>
                                @endforeach
                            </select>
                        </th>
                        <th>
                            <select name="inline_moderation_status" form="postsFiltersForm" class="form-select form-select-sm">
                                @foreach ($moderationOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(($inlineModerationStatus ?? 'any') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </th>
                        <th>
                            <select name="inline_active" form="postsFiltersForm" class="form-select form-select-sm">
                                <option value="any" @selected(($inlineActive ?? 'any') === 'any')>Any</option>
                                <option value="yes" @selected(($inlineActive ?? '') === 'yes')>Yes</option>
                                <option value="no" @selected(($inlineActive ?? '') === 'no')>No</option>
                            </select>
                        </th>
                        <th></th>
                        <th>
                            <select name="media" form="postsFiltersForm" class="form-select form-select-sm">
                                <option value="any" @selected(($media ?? 'any') === 'any')>Any</option>
                                <option value="has" @selected(($media ?? '') === 'has')>Has Media</option>
                                <option value="none" @selected(($media ?? '') === 'none')>No Media</option>
                            </select>
                        </th>
                        <th class="text-end" style="white-space:nowrap;">
                            <div class="d-inline-flex align-items-center gap-2" style="flex-wrap:nowrap;">
                                <button type="submit" form="postsFiltersForm" class="btn btn-sm btn-primary">Apply</button>
                                <a href="{{ route('admin.posts.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        @php
                            $isImpact = ($post->timeline_item_type ?? $post->source_type ?? 'post') === 'impact';
                            $owner = $post->user;
                            $circleName = optional($post->circle)->name;
                            $isActive = $isImpact
                                ? (bool) ($post->is_active ?? ! is_null($post->timeline_posted_at ?? null))
                                : $post->deleted_at === null && ! (bool) ($post->is_deleted ?? false);
                            $mediaUrl = (function ($media) {
                                if (empty($media)) {
                                    return null;
                                }

                                $items = [];

                                if (is_array($media)) {
                                    $items = $media;
                                } elseif (is_object($media)) {
                                    $items = data_get($media, 'items', []);
                                }

                                if (! is_array($items)) {
                                    return null;
                                }

                                $imageItem = collect($items)->first(function ($item) {
                                    return data_get($item, 'type') === 'image';
                                });

                                $candidate = $imageItem ?? (collect($items)->first() ?? []);
                                $url = data_get($candidate, 'url');

                                if ($url) {
                                    return $url;
                                }

                                $id = data_get($candidate, 'id') ?? data_get($candidate, 'file_id');

                                if ($id) {
                                    return url('/api/v1/files/' . $id);
                                }

                                return data_get($candidate, 'path');
                            })($post->media ?? null);
                            $isCompletedCollaboration = ! $isImpact
                                && ($post->source_type ?? null) === 'collaboration_post'
                                && ($post->source_event ?? null) === 'completed';
                            $acceptedBy = $isCompletedCollaboration ? optional($post->collaborationPost)->acceptedByUser : null;
                            $acceptedByName = $acceptedBy
                                ? ($acceptedBy->display_name ?: trim(($acceptedBy->first_name ?? '') . ' ' . ($acceptedBy->last_name ?? '')))
                                : null;
                        @endphp
                        <tr>
                            <td>{{ $post->created_at?->format('Y-m-d H:i') }}</td>
                            <td>@include('admin.partials.peer_identity', ['user' => $owner, 'circleName' => $circleName])</td>
                            <td>{{ ucfirst($post->visibility) }}</td>
                            <td>{{ $post->moderation_status ? ucfirst($post->moderation_status) : '—' }}</td>
                            <td>{{ $isActive ? 'Yes' : 'No' }}</td>
                            <td>
                                @if($isImpact)
                                    <span class="badge bg-info text-dark me-1">Impact</span>
                                @endif
                                <div>{{ \Illuminate\Support\Str::limit($post->content_text, 60) }}</div>
                                @if($isCompletedCollaboration)
                                    <div class="small text-muted mt-1">
                                        @if($acceptedBy)
                                            <div><strong>Accepted by:</strong> {{ $acceptedByName !== '' ? $acceptedByName : 'Not available' }}</div>
                                            <div><strong>Company:</strong> {{ $acceptedBy->company_name ?: '—' }}</div>
                                            <div><strong>City:</strong> {{ $acceptedBy->city ?: '—' }}</div>
                                        @else
                                            <div><strong>Accepted by:</strong> Not available</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if ($mediaUrl)
                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ $mediaUrl }}">View</a>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </td>
                            <td class="text-end" style="white-space:nowrap;">
                                @if($isImpact)
                                    <a href="{{ route('admin.impacts.show', $post->id) }}" class="btn btn-outline-primary btn-sm">View</a>
                                    @if($isActive)
                                        <form method="POST"
                                              action="{{ route('admin.posts.impacts.deactivate', $post->id) }}"
                                              style="display:inline-block; margin-left:6px;">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to deactivate this impact?')">
                                                Deactivate
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST"
                                              action="{{ route('admin.posts.impacts.activate', $post->id) }}"
                                              style="display:inline-block; margin-left:6px;">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-success btn-sm"
                                                    onclick="return confirm('Are you sure you want to activate this impact?')">
                                                Activate
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    <a href="{{ route('admin.posts.show', $post) }}" class="btn btn-outline-primary btn-sm">View</a>

                                    @if($isActive)
                                        <form method="POST"
                                              action="{{ route('admin.posts.deactivate', $post) }}"
                                              style="display:inline-block; margin-left:6px;">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to deactivate this post?')">
                                                Deactivate
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST"
                                              action="{{ route('admin.posts.restore', $post->id) }}"
                                              style="display:inline-block; margin-left:6px;">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-success btn-sm"
                                                    onclick="return confirm('Are you sure you want to activate this post?')">
                                                Activate
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No posts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $posts->links() }}</div>
@endsection
