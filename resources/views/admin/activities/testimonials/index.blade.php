@extends('admin.layouts.app')

@section('title', 'Testimonials')

@section('content')
    <style>
        .peer-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
    @php
        $displayName = function (?string $display, ?string $first, ?string $last): string {
            if ($display) {
                return $display;
            }
            $name = trim(($first ?? '') . ' ' . ($last ?? ''));
            return $name !== '' ? $name : '—';
        };

        $formatDateTime = function ($value): string {
            return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '—';
        };

        $mediaSummary = function ($media) use ($validMediaIds): array {
            if (! $media) {
                return ['has' => false, 'count' => 0];
            }

            $decoded = is_string($media) ? json_decode($media, true) : $media;
            $items = is_array($decoded) ? $decoded : [$decoded];

            $validCount = 0;
            foreach ($items as $item) {
                $id = null;
                if (is_array($item)) {
                    $id = $item['id'] ?? $item['file_id'] ?? $item['fileId'] ?? null;
                } elseif (is_string($item) && \Illuminate\Support\Str::isUuid($item)) {
                    $id = $item;
                }

                if ($id && in_array($id, $validMediaIds ?? [], true)) {
                    $validCount++;
                }
            }

            return ['has' => $validCount > 0, 'count' => $validCount];
        };

        $firstMediaId = function ($media) use ($validMediaIds): ?string {
            if (! $media) {
                return null;
            }

            $decoded = is_string($media) ? json_decode($media, true) : $media;
            $items = is_array($decoded) ? array_values($decoded) : [$decoded];

            foreach ($items as $item) {
                $id = null;
                if (is_array($item)) {
                    $id = $item['id'] ?? $item['file_id'] ?? $item['fileId'] ?? null;
                } elseif (is_string($item) && \Illuminate\Support\Str::isUuid($item)) {
                    $id = $item;
                }

                if ($id && in_array($id, $validMediaIds ?? [], true)) {
                    return $id;
                }
            }

            return null;
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Testimonials</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">Total Testimonials: {{ number_format($total) }}</span>
        </div>
    </div>

    <form id="testimonialsFiltersForm" method="GET" action="{{ route('admin.activities.testimonials.index') }}">
    @include('admin.components.activity-filter-bar-v2', [
        'actionUrl' => route('admin.activities.testimonials.index'),
        'resetUrl' => route('admin.activities.testimonials.index'),
        'filters' => $filters,
        'circles' => $circles ?? collect(),
        'showExport' => true,
        'exportUrl' => route('admin.activities.testimonials.export', request()->except(['content'])),
        'renderFormTag' => false,
        'formId' => 'testimonialsFiltersForm',
    ])

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong>Top 5 Peers</strong>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Rank</th>
                        <th>Peer Name</th>
                        <th>Total Testimonials</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topMembers as $index => $member)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $member->peer_name ?? $displayName($member->display_name ?? null, $member->first_name ?? null, $member->last_name ?? null),
                                    'company' => $member->peer_company ?? '',
                                    'city' => $member->peer_city ?? '',
                                    'maxWidth' => 260,
                                ])
                            </td>
                            <td>{{ $member->total_count ?? 0 }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Content</th>
                        <th>Media</th>
                        <th>Created At</th>
                    </tr>
                    <tr>
                        <th>
                            <input type="text" name="from_peer" value="{{ $tableFilters['from_peer'] ?? '' }}" class="form-control form-control-sm" placeholder="From">
                        </th>
                        <th>
                            <input type="text" name="to_peer" value="{{ $tableFilters['to_peer'] ?? '' }}" class="form-control form-control-sm" placeholder="To">
                        </th>
                        <th></th>
                        <th>
                            <select name="media" class="form-select form-select-sm">
                                <option value="" @selected(($tableFilters['media'] ?? '') === '')>Any</option>
                                <option value="yes" @selected(($tableFilters['media'] ?? '') === 'yes')>Yes</option>
                                <option value="no" @selected(($tableFilters['media'] ?? '') === 'no')>No</option>
                            </select>
                        </th>
                        <th class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.activities.testimonials.index') }}">Reset</a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $testimonial)
                        @php
                            $actorName = $displayName($testimonial->actor_display_name ?? null, $testimonial->actor_first_name ?? null, $testimonial->actor_last_name ?? null);
                            $peerName = $displayName($testimonial->peer_display_name ?? null, $testimonial->peer_first_name ?? null, $testimonial->peer_last_name ?? null);
                            $mediaInfo = $mediaSummary($testimonial->media ?? null);
                            $mediaId = $firstMediaId($testimonial->media ?? null);
                        @endphp
                        <tr>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $testimonial->from_user_name ?? $actorName,
                                    'company' => $testimonial->from_company ?? '',
                                    'city' => $testimonial->from_city ?? '',
                                ])
                            </td>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $testimonial->to_user_name ?? $peerName,
                                    'company' => $testimonial->to_company ?? '',
                                    'city' => $testimonial->to_city ?? '',
                                ])
                            </td>
                            <td class="text-muted">{{ $testimonial->content ?? '—' }}</td>
                            <td>
                                @if ($mediaInfo['has'] && $mediaId)
                                    <span class="badge bg-success">Yes ({{ $mediaInfo['count'] }})</span>
                                    <a href="{{ url('/api/v1/files/' . $mediaId) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2">View</a>
                                @else
                                    <span class="text-muted">No Media</span>
                                @endif
                            </td>
                            <td>{{ $formatDateTime($testimonial->created_at ?? null) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No testimonials found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    </form>

    <div class="mt-3">
        {{ $items->links() }}
    </div>

@endsection
