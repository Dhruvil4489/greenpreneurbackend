<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ActivitiesP2PMeetingsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $baseQuery = $this->baseQuery($request, $filters);
        $total = (clone $baseQuery)->count();

        $items = $baseQuery
            ->select([
                'activity.id',
                'activity.meeting_date',
                'activity.meeting_place',
                'activity.remarks',
                'activity.created_at',
                DB::raw($this->hasMediaSelectExpression() . ' as has_media'),
                DB::raw($this->mediaReferenceSelectExpression() . ' as media_reference'),
                'actor.display_name as actor_display_name',
                'actor.first_name as actor_first_name',
                'actor.last_name as actor_last_name',
                'actor.email as actor_email',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', actor.first_name, actor.last_name)), ''), actor.display_name, '—') as from_user_name"),
                DB::raw("coalesce(actor.company_name, '') as from_company"),
                DB::raw("coalesce(actor.city, '') as from_city"),
                'peer.display_name as peer_display_name',
                'peer.first_name as peer_first_name',
                'peer.last_name as peer_last_name',
                'peer.email as peer_email',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as to_user_name"),
                DB::raw("coalesce(peer.company_name, '') as to_company"),
                DB::raw("coalesce(peer.city, '') as to_city"),
            ])
            ->orderByDesc('activity.meeting_date')
            ->orderByDesc('activity.created_at')
            ->paginate(20)
            ->withQueryString();

        $topMembers = $this->topMembers($request);

        return view('admin.activities.p2p_meetings.index', [
            'items' => $items,
            'filters' => $filters,
            'topMembers' => $topMembers,
            'total' => $total,
            'circles' => $this->circleOptions(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'p2p_meetings_' . now()->format('Ymd_His') . '.csv';

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
                    'Connected With Name',
                    'Connected With Email',
                    'Meeting Date',
                    'Meeting Place',
                    'Remarks',
                    'Media Count',
                    'Media URLs',
                    'Media JSON',
                    'Created At',
                ]);

                $this->baseQuery($request, $filters)
                    ->select([
                        'activity.id',
                        'activity.meeting_date',
                        'activity.meeting_place',
                        'activity.remarks',
                        'activity.created_at',
                        'actor.display_name as actor_display_name',
                        'actor.first_name as actor_first_name',
                        'actor.last_name as actor_last_name',
                        'actor.email as actor_email',
                        'peer.display_name as peer_display_name',
                        'peer.first_name as peer_first_name',
                        'peer.last_name as peer_last_name',
                        'peer.email as peer_email',
                        DB::raw($this->hasMediaSelectExpression() . ' as has_media'),
                        DB::raw($this->mediaReferenceSelectExpression() . ' as media_reference'),
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
                            $peerName = $this->formatUserName(
                                $row->peer_display_name,
                                $row->peer_first_name,
                                $row->peer_last_name
                            );

                            fputcsv($handle, [
                                $row->id,
                                $actorName,
                                $row->actor_email ?? '',
                                $peerName,
                                $row->peer_email ?? '',
                                $row->meeting_date ?? '',
                                $row->meeting_place ?? '',
                                $row->remarks ?? '',
                                (int) ($row->has_media ?? 0),
                                $this->mediaReferenceForExport($row->media_reference ?? null),
                                $this->mediaReferenceForExport($row->media_reference ?? null),
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

    private function filters(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return [
            'q' => trim((string) $request->query('q', $request->query('search', ''))),
            'from' => $from,
            'to' => $to,
            'from_at' => $this->parseDayBoundary($from, false),
            'to_at' => $this->parseDayBoundary($to, true),
            'circle_id' => (string) $request->query('circle_id', ''),
            'from_user' => trim((string) $request->query('from_user', '')),
            'to_user' => trim((string) $request->query('to_user', '')),
            'meeting_date' => trim((string) $request->query('meeting_date', '')),
            'meeting_place' => trim((string) $request->query('meeting_place', '')),
            'remarks' => trim((string) $request->query('remarks', '')),
            'has_media' => (string) $request->query('has_media', ''),
        ];
    }

    private function baseQuery(Request $request, array $filters)
    {
        $query = DB::table('p2p_meetings as activity')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.initiator_user_id')
            ->leftJoin('users as peer', 'peer.id', '=', 'activity.peer_user_id')
            ->whereNull('activity.deleted_at')
            ->where('activity.is_deleted', false);

        if ($filters['q'] !== '') {
            $query->leftJoin('cities as actor_city', 'actor_city.id', '=', 'actor.city_id');
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('actor.display_name', 'ILIKE', $like)
                    ->orWhere('actor.first_name', 'ILIKE', $like)
                    ->orWhere('actor.last_name', 'ILIKE', $like)
                    ->orWhere('actor.company_name', 'ILIKE', $like)
                    ->orWhere('actor.city', 'ILIKE', $like)
                    ->orWhere('actor_city.name', 'ILIKE', $like);
            });
        }

        if ($filters['from_at']) {
            $query->where('activity.created_at', '>=', $filters['from_at']);
        }

        if ($filters['to_at']) {
            $query->where('activity.created_at', '<=', $filters['to_at']);
        }

        if (! empty($filters['circle_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'actor.id')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        if ($filters['from_user'] !== '') {
            $like = $this->escapeLike($filters['from_user']);
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw("coalesce(nullif(trim(concat_ws(' ', actor.first_name, actor.last_name)), ''), actor.display_name, '') ILIKE ?", ["%{$like}%"])
                    ->orWhere('actor.company_name', 'ILIKE', "%{$like}%")
                    ->orWhere('actor.city', 'ILIKE', "%{$like}%");
            });
        }

        if ($filters['to_user'] !== '') {
            $like = $this->escapeLike($filters['to_user']);
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '') ILIKE ?", ["%{$like}%"])
                    ->orWhere('peer.company_name', 'ILIKE', "%{$like}%")
                    ->orWhere('peer.city', 'ILIKE', "%{$like}%");
            });
        }

        if ($filters['meeting_date'] !== '') {
            $meetingDate = $this->parseInputDate($filters['meeting_date']);
            if ($meetingDate) {
                $query->whereDate('activity.meeting_date', $meetingDate->toDateString());
            }
        }

        if ($filters['meeting_place'] !== '') {
            $query->where('activity.meeting_place', 'ILIKE', '%' . $this->escapeLike($filters['meeting_place']) . '%');
        }

        if ($filters['remarks'] !== '') {
            $query->where('activity.remarks', 'ILIKE', '%' . $this->escapeLike($filters['remarks']) . '%');
        }

        if ($filters['has_media'] === 'yes') {
            $this->applyHasMediaFilter($query, true);
        } elseif ($filters['has_media'] === 'no') {
            $this->applyHasMediaFilter($query, false);
        }

        $this->applyScopeToActivityQuery($query, 'activity.initiator_user_id', 'activity.peer_user_id');

        return $query;
    }

    private function topMembers(Request $request)
    {
        $filters = $this->filters($request);

        $query = DB::table('p2p_meetings as activity')
            ->join('users as actor', 'actor.id', '=', 'activity.initiator_user_id')
            ->whereNull('activity.deleted_at')
            ->where('activity.is_deleted', false);

        if (! empty($filters['circle_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'actor.id')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        $this->applyScopeToActivityQuery($query, 'activity.initiator_user_id', 'activity.peer_user_id');

        return $query
            ->groupBy(
                'activity.initiator_user_id',
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
                'activity.initiator_user_id as actor_id',
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

    private function meetingMediaColumn(): ?string
    {
        static $column;
        if (func_num_args() === 0 && isset($column)) {
            return $column;
        }
        foreach (['media', 'media_id', 'media_file_id', 'file_id', 'attachment_id', 'media_url'] as $candidate) {
            if (Schema::hasColumn('p2p_meetings', $candidate)) {
                $column = $candidate;
                return $column;
            }
        }
        $column = null;
        return null;
    }

    private function hasMediaSelectExpression(): string
    {
        $column = $this->meetingMediaColumn();
        if ($column === 'media_url') {
            return "CASE WHEN NULLIF(activity.media_url, '') IS NULL THEN 0 ELSE 1 END";
        }
        if ($column === 'media') {
            return "CASE WHEN activity.media IS NULL OR activity.media::text = '[]' THEN 0 ELSE 1 END";
        }
        if ($column) {
            return "CASE WHEN activity.{$column} IS NULL THEN 0 ELSE 1 END";
        }
        return '0';
    }

    private function mediaReferenceSelectExpression(): string
    {
        $column = $this->meetingMediaColumn();
        return $column ? "activity.{$column}" : 'NULL';
    }

    private function applyHasMediaFilter($query, bool $hasMedia): void
    {
        $column = $this->meetingMediaColumn();
        if (! $column) {
            if ($hasMedia) {
                $query->whereRaw('1 = 0');
            }
            return;
        }
        $qualified = "activity.{$column}";
        if ($column === 'media_url') {
            $query->whereRaw($hasMedia ? "NULLIF({$qualified}, '') IS NOT NULL" : "NULLIF({$qualified}, '') IS NULL");
            return;
        }
        if ($column === 'media') {
            $query->whereRaw($hasMedia ? "{$qualified} IS NOT NULL AND {$qualified}::text <> '[]'" : "{$qualified} IS NULL OR {$qualified}::text = '[]'");
            return;
        }
        $hasMedia ? $query->whereNotNull($qualified) : $query->whereNull($qualified);
    }

    private function mediaReferenceForExport($value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function parseInputDate(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('d-m-Y', $value);
        } catch (\Throwable $exception) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $exception) {
                return null;
            }
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
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

    private function applyScopeToActivityQuery($query, string $primaryColumn, ?string $peerColumn): void
    {
        $admin = auth('admin')->user();

        AdminCircleScope::applyToActivityQuery($query, $admin, $primaryColumn, $peerColumn);
        app(IndustryScopeService::class)->applyToActivityQuery($query, $admin, array_filter([$primaryColumn, $peerColumn]));
    }

    private function formatUserName(?string $displayName, ?string $firstName, ?string $lastName): string
    {
        if ($displayName) {
            return $displayName;
        }

        $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        return $name !== '' ? $name : '—';
    }

}
