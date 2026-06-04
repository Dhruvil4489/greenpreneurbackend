<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ActivitiesReferralsController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);

        $baseQuery = $this->baseQuery($filters);
        $total = (clone $baseQuery)->count();

        $items = $baseQuery
            ->select([
                'activity.id',
                'activity.referral_type',
                'activity.referral_date',
                'activity.referral_of',
                'activity.phone',
                'activity.email',
                'activity.address',
                'activity.hot_value',
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
            ->orderByDesc('activity.created_at')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $topMembers = $this->topMembers($request);

        return view('admin.activities.referrals.index', [
            'items' => $items,
            'filters' => $filters,
            'topMembers' => $topMembers,
            'total' => $total,
            'circles' => $this->circleOptions(),
            'types' => $this->referralTypeOptions(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->buildFilters($request);
        $filename = 'referrals_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filters) {
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
                    'Related Peer Name',
                    'Related Peer Email',
                    'Referral Type',
                    'Referral Date',
                    'Referral Of',
                    'Phone',
                    'Email',
                    'Address',
                    'Hot Value',
                    'Remarks',
                    'Media Count',
                    'Media URLs',
                    'Media JSON',
                    'Created At',
                ]);

                $this->baseQuery($filters)
                    ->select([
                        'activity.id',
                        'activity.referral_type',
                        'activity.referral_date',
                        'activity.referral_of',
                        'activity.phone',
                        'activity.email',
                        'activity.address',
                        'activity.hot_value',
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
                                $row->referral_type ?? '',
                                $row->referral_date ?? '',
                                $row->referral_of ?? '',
                                $row->phone ?? '',
                                $row->email ?? '',
                                $row->address ?? '',
                                $row->hot_value ?? '',
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

    private function buildFilters(Request $request): array
    {
        $from = $request->get('from');
        $to = $request->get('to');
        $fromAtRaw = $request->get('from_at') ?? $from;
        $toAtRaw = $request->get('to_at') ?? $to;

        $filters = [
            'q' => trim((string) $request->get('q', $request->get('search', ''))),
            'from' => $from,
            'to' => $to,
            'from_at' => $fromAtRaw,
            'to_at' => $toAtRaw,
            'referral_type' => trim((string) ($request->get('referral_type') ?? $request->get('type', ''))),
            'per_page' => (int) $request->get('per_page', 20),
            'circle_id' => (string) $request->get('circle_id', ''),
            'from_user' => trim((string) $request->get('from_user', '')),
            'to_user' => trim((string) $request->get('to_user', '')),
            'type' => trim((string) $request->get('type', '')),
            'referral_date' => trim((string) $request->get('referral_date', '')),
            'referral_of' => trim((string) $request->get('referral_of', '')),
            'phone' => trim((string) $request->get('phone', '')),
            'email' => trim((string) $request->get('email', '')),
            'hot_value' => trim((string) $request->get('hot_value', '')),
            'remarks' => trim((string) $request->get('remarks', '')),
            'has_media' => (string) $request->get('has_media', ''),
        ];

        $filters['from_dt'] = $this->parseDayBoundary($filters['from_at'], false);
        $filters['to_dt'] = $this->parseDayBoundary($filters['to_at'], true);

        if ($filters['per_page'] <= 0) {
            $filters['per_page'] = 20;
        }

        return [
            ...$filters,
        ];
    }

    private function baseQuery(array $filters)
    {
        $query = DB::table('referrals as activity')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.from_user_id')
            ->leftJoin('users as peer', 'peer.id', '=', 'activity.to_user_id')
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

        if (! empty($filters['referral_type'])) {
            $query->where('activity.referral_type', $filters['referral_type']);
        }

        if ($filters['from_user'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['from_user']) . '%';
            $query->where(function ($inner) use ($like) {
                $inner->where('actor.display_name', 'ILIKE', $like)
                    ->orWhere('actor.first_name', 'ILIKE', $like)
                    ->orWhere('actor.last_name', 'ILIKE', $like)
                    ->orWhereRaw("concat_ws(' ', coalesce(actor.first_name, ''), coalesce(actor.last_name, '')) ILIKE ?", [$like]);
            });
        }

        if ($filters['to_user'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['to_user']) . '%';
            $query->where(function ($inner) use ($like) {
                $inner->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like)
                    ->orWhereRaw("concat_ws(' ', coalesce(peer.first_name, ''), coalesce(peer.last_name, '')) ILIKE ?", [$like]);
            });
        }

        if ($filters['type'] !== '' && $filters['referral_type'] === '') {
            $query->where('activity.referral_type', $filters['type']);
        }

        if ($filters['referral_date'] !== '') {
            $parsedDate = $this->parseInputDate($filters['referral_date']);
            if ($parsedDate) {
                $query->whereDate('activity.referral_date', '=', $parsedDate->toDateString());
            }
        }

        if ($filters['referral_of'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['referral_of']) . '%';
            $query->where('activity.referral_of', 'ILIKE', $like);
        }

        if ($filters['phone'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['phone']) . '%';
            $query->where('activity.phone', 'ILIKE', $like);
        }

        if ($filters['email'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['email']) . '%';
            $query->where('activity.email', 'ILIKE', $like);
        }

        if ($filters['hot_value'] !== '' && is_numeric($filters['hot_value'])) {
            $query->where('activity.hot_value', (int) $filters['hot_value']);
        }

        if ($filters['remarks'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['remarks']) . '%';
            $query->where('activity.remarks', 'ILIKE', $like);
        }

        if ($filters['has_media'] === '1') {
            $this->applyHasMediaFilter($query, true);
        }

        if ($filters['has_media'] === '0') {
            $this->applyHasMediaFilter($query, false);
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

        $this->applyScopeToActivityQuery($query, 'activity.from_user_id', 'activity.to_user_id');

        return $query;
    }

    private function topMembers(Request $request)
    {
        $filters = $this->buildFilters($request);

        $query = DB::table('referrals as activity')
            ->join('users as actor', 'actor.id', '=', 'activity.from_user_id')
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

        $this->applyScopeToActivityQuery($query, 'activity.from_user_id', 'activity.to_user_id');

        return $query
            ->groupBy(
                'activity.from_user_id',
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
                'activity.from_user_id as actor_id',
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

    private function referralTypeOptions()
    {
        return Referral::query()
            ->whereNotNull('referral_type')
            ->select('referral_type')
            ->distinct()
            ->orderBy('referral_type')
            ->pluck('referral_type')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values();
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

    private function referralMediaColumn(): ?string
    {
        static $column;

        if (func_num_args() === 0 && isset($column)) {
            return $column;
        }

        foreach (['media_id', 'media_file_id', 'file_id', 'attachment_id', 'media_url', 'document_id', 'image_id'] as $candidate) {
            if (Schema::hasColumn('referrals', $candidate)) {
                $column = $candidate;
                return $column;
            }
        }

        $column = null;

        return null;
    }

    private function hasMediaSelectExpression(): string
    {
        $column = $this->referralMediaColumn();

        if ($column === 'media_url') {
            return "CASE WHEN NULLIF(activity.media_url, '') IS NULL THEN 0 ELSE 1 END";
        }

        if ($column) {
            return "CASE WHEN activity.{$column} IS NULL THEN 0 ELSE 1 END";
        }

        return '0';
    }

    private function mediaReferenceSelectExpression(): string
    {
        $column = $this->referralMediaColumn();

        if ($column) {
            return "activity.{$column}";
        }

        return 'NULL';
    }

    private function applyHasMediaFilter($query, bool $hasMedia): void
    {
        $column = $this->referralMediaColumn();

        if (! $column) {
            if ($hasMedia) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $qualified = "activity.{$column}";

        if ($column === 'media_url') {
            if ($hasMedia) {
                $query->whereRaw("NULLIF({$qualified}, '') IS NOT NULL");
            } else {
                $query->whereRaw("NULLIF({$qualified}, '') IS NULL");
            }

            return;
        }

        if ($hasMedia) {
            $query->whereNotNull($qualified);
        } else {
            $query->whereNull($qualified);
        }
    }

    private function mediaReferenceForExport($value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
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

