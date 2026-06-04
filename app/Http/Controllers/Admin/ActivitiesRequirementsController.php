<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\Requirement;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ActivitiesRequirementsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);

        $baseQuery = $this->baseQuery($filters);
        $total = (clone $baseQuery)->count();

        $items = $baseQuery
            ->select([
                'activity.id',
                'activity.subject',
                'activity.description',
                'activity.media',
                'activity.region_filter',
                'activity.category_filter',
                'activity.status',
                'activity.created_at',
                'actor.display_name as actor_display_name',
                'actor.first_name as actor_first_name',
                'actor.last_name as actor_last_name',
                'actor.email as actor_email',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', actor.first_name, actor.last_name)), ''), actor.display_name, '—') as from_user_name"),
                DB::raw("coalesce(actor.company_name, '') as from_company"),
                DB::raw("coalesce(actor.city, '') as from_city"),
                DB::raw('NULL as to_user_name'),
                DB::raw("'' as to_company"),
                DB::raw("'' as to_city"),
            ])
            ->orderByDesc('activity.created_at')
            ->paginate(20)
            ->withQueryString();

        $topMembers = $this->topMembers($request);

        return view('admin.activities.requirements.index', [
            'items' => $items,
            'filters' => $filters,
            'topMembers' => $topMembers,
            'total' => $total,
            'circles' => $this->circleOptions(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->buildFilters($request);
        $filename = 'requirements_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($request, $filters) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $handle = fopen('php://output', 'w');

            try {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, [
                    'ID',
                    'Created By Name',
                    'Created By Email',
                    'Subject',
                    'Description',
                    'Region Filter JSON',
                    'Category Filter JSON',
                    'Status',
                    'Media Count',
                    'Media URLs',
                    'Media JSON',
                    'Created At',
                ]);

                $this->baseQuery($filters)
                    ->select([
                        'activity.id',
                        'activity.subject',
                        'activity.description',
                        'activity.media',
                        'activity.region_filter',
                        'activity.category_filter',
                        'activity.status',
                        'activity.created_at',
                        'actor.display_name as actor_display_name',
                        'actor.first_name as actor_first_name',
                        'actor.last_name as actor_last_name',
                        'actor.email as actor_email',
                    ])
                    ->orderBy('activity.created_at')
                    ->orderBy('activity.id')
                    ->chunk(500, function ($rows) use ($handle) {
                        foreach ($rows as $row) {
                            $actorName = $this->formatUserName(
                                $row->actor_display_name,
                                $row->actor_first_name,
                                $row->actor_last_name
                            );

                            fputcsv($handle, [
                                $row->id,
                                $actorName,
                                $row->actor_email ?? '',
                                $row->subject ?? '',
                                $row->description ?? '',
                                $this->stringifyField($row->region_filter ?? null),
                                $this->stringifyField($row->category_filter ?? null),
                                $row->status ?? '',
                                $this->mediaCount($row->media ?? null),
                                $this->mediaUrls($row->media ?? null),
                                $this->mediaJson($row->media ?? null),
                                $row->created_at ?? '',
                            ]);
                        }
                    });
            } finally {
                fclose($handle);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function buildFilters(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $fromAtRaw = $request->query('from_at', $from);
        $toAtRaw = $request->query('to_at', $to);

        return [
            'q' => trim((string) $request->query('q', $request->query('search', ''))),
            'from' => $from,
            'to' => $to,
            'from_at' => $fromAtRaw,
            'to_at' => $toAtRaw,
            'from_dt' => $this->parseDayBoundary($fromAtRaw, false),
            'to_dt' => $this->parseDayBoundary($toAtRaw, true),
            'circle_id' => (string) $request->query('circle_id', ''),
            'from_user' => trim((string) $request->query('from_user', '')),
            'subject' => trim((string) $request->query('subject', '')),
            'region' => trim((string) $request->query('region', '')),
            'category' => trim((string) $request->query('category', '')),
            'status' => trim((string) $request->query('status', '')),
            'has_media' => (string) $request->query('has_media', ''),
            'per_page' => (int) $request->query('per_page', 20),
        ];
    }

    private function baseQuery(array $filters)
    {
        $query = DB::table('requirements as activity')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.user_id')
            ->whereNull('activity.deleted_at');

        if ($filters['q'] !== '') {
            $query->leftJoin('cities as actor_city', 'actor_city.id', '=', 'actor.city_id');
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['q']) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('actor.display_name', 'ILIKE', $like)
                    ->orWhere('actor.first_name', 'ILIKE', $like)
                    ->orWhere('actor.last_name', 'ILIKE', $like)
                    ->orWhere('actor.company_name', 'ILIKE', $like)
                    ->orWhere('actor.city', 'ILIKE', $like)
                    ->orWhere('actor_city.name', 'ILIKE', $like);
            });
        }

        if ($filters['from_user'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['from_user']) . '%';
            $query->where(function ($inner) use ($like) {
                $inner->where('actor.display_name', 'ILIKE', $like)
                    ->orWhere('actor.first_name', 'ILIKE', $like)
                    ->orWhere('actor.last_name', 'ILIKE', $like)
                    ->orWhereRaw("concat_ws(' ', coalesce(actor.first_name, ''), coalesce(actor.last_name, '')) ILIKE ?", [$like]);
            });
        }

        if ($filters['subject'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['subject']) . '%';
            $query->where('activity.subject', 'ILIKE', $like);
        }

        if ($filters['region'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['region']) . '%';
            $query->whereRaw("coalesce(nullif(activity.region_filter->>'region_label', ''), nullif(activity.region_filter->>'region_name', ''), nullif(activity.region_filter->>'city_name', '')) ILIKE ?", [$like]);
        }

        if ($filters['category'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['category']) . '%';
            $query->whereRaw("coalesce(nullif(activity.category_filter->>'category', ''), nullif(activity.category_filter->>'name', '')) ILIKE ?", [$like]);
        }

        if ($filters['status'] !== '') {
            $query->where('activity.status', $filters['status']);
        }

        if ($filters['has_media'] === '1') {
            $query->whereNotNull('activity.media')->whereRaw("activity.media::text <> '[]'");
        }

        if ($filters['has_media'] === '0') {
            $query->where(function ($inner) {
                $inner->whereNull('activity.media')
                    ->orWhereRaw("activity.media::text = '[]'");
            });
        }

        $from = $filters['from_dt'] ?? null;
        $to = $filters['to_dt'] ?? null;

        $query->when($from, fn ($inner) => $inner->where('activity.created_at', '>=', $from))
            ->when($to, fn ($inner) => $inner->where('activity.created_at', '<=', $to));

        if (! empty($filters['circle_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'actor.id')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        $this->applyScopeToActivityQuery($query, 'activity.user_id');

        return $query;
    }

    private function topMembers(Request $request)
    {
        $filters = $this->buildFilters($request);

        $query = DB::table('requirements as activity')
            ->join('users as actor', 'actor.id', '=', 'activity.user_id')
            ->whereNull('activity.deleted_at');

        if (! empty($filters['circle_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'actor.id')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        $this->applyScopeToActivityQuery($query, 'activity.user_id');

        return $query
            ->groupBy(
                'activity.user_id',
                'actor.display_name',
                'actor.first_name',
                'actor.last_name',
                'actor.email',
                'actor.company_name',
                'actor.city'
            )
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(5)
            ->select([
                'activity.user_id as actor_id',
                'actor.display_name',
                'actor.first_name',
                'actor.last_name',
                'actor.email',
                'actor.company_name',
                'actor.city',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', actor.first_name, actor.last_name)), ''), actor.display_name, '—') as peer_name"),
                DB::raw("coalesce(actor.company_name, '') as peer_company"),
                DB::raw("coalesce(actor.city, '') as peer_city"),
                DB::raw('count(*) as total_count'),
            ])
            ->get();
    }

    private function circleOptions()
    {
        return DB::table('circles')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }


    private function statusOptions()
    {
        return Requirement::query()
            ->whereNotNull('status')
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values();
    }

    private function parseDayBoundary($value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);

            return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function applyScopeToActivityQuery($query, string $primaryColumn): void
    {
        $admin = auth('admin')->user();

        AdminCircleScope::applyToActivityQuery($query, $admin, $primaryColumn, null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, $admin, [$primaryColumn]);
    }

    private function formatUserName(?string $displayName, ?string $firstName, ?string $lastName): string
    {
        if ($displayName) {
            return $displayName;
        }

        $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        return $name !== '' ? $name : '—';
    }

    private function mediaCount($media): int
    {
        return count($this->normalizeMedia($media));
    }

    private function mediaUrls($media): string
    {
        $urls = [];

        foreach ($this->normalizeMedia($media) as $item) {
            $url = $this->resolveMediaUrl($item);
            if ($url) {
                $urls[] = $url;
            }
        }

        return implode(',', $urls);
    }

    private function mediaJson($media): string
    {
        $normalized = $this->normalizeMedia($media);

        return $normalized ? json_encode($normalized) : '';
    }

    private function normalizeMedia($media): array
    {
        if (! $media) {
            return [];
        }

        if (is_string($media)) {
            $decoded = json_decode($media, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [$media];
        }

        if (is_array($media)) {
            return $media;
        }

        return [$media];
    }

    private function resolveMediaUrl($item): ?string
    {
        if (is_array($item)) {
            $url = $item['url'] ?? null;
            $id = $item['id'] ?? null;

            if ($url) {
                return $url;
            }

            if ($id && Str::isUuid($id)) {
                return url('/api/v1/files/' . $id);
            }

            return $id ?: null;
        }

        if (is_string($item)) {
            if (str_starts_with($item, 'http://') || str_starts_with($item, 'https://')) {
                return $item;
            }

            if (Str::isUuid($item)) {
                return url('/api/v1/files/' . $item);
            }

            return $item;
        }

        return null;
    }

    private function stringifyField($value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return $value !== null ? (string) $value : '';
    }
}
