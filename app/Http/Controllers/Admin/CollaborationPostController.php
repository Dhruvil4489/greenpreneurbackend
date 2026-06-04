<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollaborationPost;
use App\Models\CollaborationType;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use App\Support\CollaborationFormatter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CollaborationPostController extends Controller
{
    public function index(Request $request): View
    {
        $rowsPerPage = (int) $request->query('per_page', 20);
        if (! in_array($rowsPerPage, [10, 20, 50, 100], true)) {
            $rowsPerPage = 20;
        }

        $filters = $this->collectFilters($request, $rowsPerPage);

        $query = CollaborationPost::query()
            ->leftJoin('users as peer', 'peer.id', '=', 'collaboration_posts.user_id')
            ->with(['collaborationType'])
            ->select([
                'collaboration_posts.*',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as peer_name"),
                DB::raw("coalesce(peer.company_name, '') as peer_company"),
                DB::raw("coalesce(peer.city, '') as peer_city"),
            ])
            ->latest('collaboration_posts.created_at');

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'collaboration_posts.user_id', null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, Auth::guard('admin')->user(), array_filter([
            'collaboration_posts.user_id',
            Schema::hasColumn('collaboration_posts', 'member_id') ? 'collaboration_posts.member_id' : null,
        ]));

        $this->applyFilters($request, $query);

        $posts = $query->paginate($rowsPerPage)->withQueryString();

        $statuses = CollaborationPost::query()
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter()
            ->values();

        $types = class_exists(CollaborationType::class)
            ? CollaborationType::query()->select('id', 'slug', 'name')->orderBy('name')->get()
            : collect();

        return view('admin.collaborations.index', [
            'posts' => $posts,
            'filters' => $filters,
            'statuses' => $statuses,
            'types' => $types,
            'circles' => DB::table('circles')->select(['id','name'])->orderBy('name')->get(),
            'rowsPerPage' => $rowsPerPage,
            'total' => $posts->total(),
            'from' => $posts->firstItem(),
            'to' => $posts->lastItem(),
        ]);
    }

    public function export(Request $request)
    {
        $query = CollaborationPost::query()
            ->leftJoin('users as peer', 'peer.id', '=', 'collaboration_posts.user_id')
            ->with(['collaborationType'])
            ->select([
                'collaboration_posts.*',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as peer_name"),
                DB::raw("coalesce(peer.company_name, '') as peer_company"),
                DB::raw("coalesce(peer.city, '') as peer_city"),
            ])
            ->latest('collaboration_posts.created_at');

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'collaboration_posts.user_id', null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, Auth::guard('admin')->user(), array_filter([
            'collaboration_posts.user_id',
            Schema::hasColumn('collaboration_posts', 'member_id') ? 'collaboration_posts.member_id' : null,
        ]));

        $this->applyFilters($request, $query);

        $selectedIds = collect((array) $request->input('selected_ids', []))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->values()
            ->all();

        if ($selectedIds !== []) {
            $query->whereIn('id', $selectedIds);
        }

        $filename = 'collaborations_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'post_id',
                'peer_name',
                'peer_company',
                'peer_city',
                'collaboration_type',
                'title',
                'scope',
                'preferred_mode',
                'business_stage',
                'years_in_operation',
                'status',
                'created_at',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $post) {
                    $peerName = $post->peer_name
                        ?? $post->person_name
                        ?? $post->name
                        ?? '—';
                    $company = ($post->peer_company ?? null)
                        ?? $post->company
                        ?? $post->company_name
                        ?? $post->business_name
                        ?? '—';
                    $city = ($post->peer_city ?? null)
                        ?? $post->city
                        ?? $post->user_city
                        ?? '—';

                    $typeLabel = $post->collaborationType?->name
                        ?? CollaborationFormatter::humanize($post->collaboration_type);
                    $scopeLabel = CollaborationFormatter::humanize($post->scope ?? $post->collaboration_scope ?? $post->scope_text);
                    $preferredLabel = CollaborationFormatter::humanize($post->preferred_mode ?? $post->preferred_model ?? $post->meeting_mode ?? $post->mode);
                    $businessStageLabel = CollaborationFormatter::humanize($post->business_stage ?? $post->stage ?? $post->business_stage_text);
                    $yearsLabel = CollaborationFormatter::humanize($post->year_in_operation ?? $post->years_in_operation ?? $post->operating_years ?? $post->years);
                    $statusLabel = CollaborationFormatter::humanize($post->status);

                    fputcsv($out, [
                        $post->id,
                        $peerName,
                        $company,
                        $city,
                        $typeLabel,
                        $post->title ?? $post->collaboration_title ?? $post->subject ?? '—',
                        $scopeLabel,
                        $preferredLabel,
                        $businessStageLabel,
                        $yearsLabel,
                        $statusLabel,
                        optional($post->created_at)->format('Y-m-d H:i:s') ?? '—',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function show(string $id, Request $request): View
    {
        $post = CollaborationPost::query()
            ->with(['user', 'collaborationType'])
            ->findOrFail($id);

        if (! AdminCircleScope::userInScope(Auth::guard('admin')->user(), (string) $post->user_id) || ! app(IndustryScopeService::class)->userInScope(Auth::guard('admin')->user(), (string) $post->user_id)) {
            abort(403);
        }

        return view('admin.collaborations.show', [
            'post' => $post,
            'backUrl' => route('admin.collaborations.index', $request->query()),
        ]);
    }

    private function collectFilters(Request $request, int $rowsPerPage): array
    {
        $from = (string) $request->query('from', $request->query('created_from', ''));
        $to = (string) $request->query('to', $request->query('created_to', ''));

        return [
            'q' => trim((string) $request->query('q', '')),
            'peer_name' => trim((string) $request->query('peer_name', '')),
            'collaboration_type' => trim((string) $request->query('collaboration_type', '')),
            'title' => trim((string) $request->query('title', '')),
            'scope' => trim((string) $request->query('scope', '')),
            'preferred_mode' => trim((string) $request->query('preferred_mode', '')),
            'business_stage' => trim((string) $request->query('business_stage', '')),
            'year_in_operation' => trim((string) $request->query('year_in_operation', '')),
            'status' => trim((string) $request->query('status', '')),
            'circle_id' => $request->query('circle_id'),
            'from' => $from,
            'to' => $to,
            'created_from' => $from,
            'created_to' => $to,
            'per_page' => $rowsPerPage,
        ];
    }

    private function applyFilters(Request $request, Builder $query): void
    {
        $filters = $this->collectFilters($request, (int) $request->query('per_page', 20));

        $titleColumns = $this->existingPostColumns(['title', 'collaboration_title', 'subject']);
        $scopeColumns = $this->existingPostColumns(['scope', 'collaboration_scope', 'scope_text']);
        $preferredModeColumns = $this->existingPostColumns(['preferred_mode', 'preferred_model', 'meeting_mode', 'mode']);
        $businessStageColumns = $this->existingPostColumns(['business_stage', 'stage', 'business_stage_text']);
        $yearOperationColumns = $this->existingPostColumns(['year_in_operation', 'years_in_operation', 'operating_years', 'years']);

        if ($filters['q'] !== '') {
            $value = $filters['q'];
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $value) . '%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like)
                    ->orWhere('peer.company_name', 'ILIKE', $like)
                    ->orWhere('peer.city', 'ILIKE', $like);
            });
        }

        if ($filters['peer_name'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['peer_name']) . '%';
            $query->where(function (Builder $inner) use ($like) {
                $inner->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like)
                    ->orWhere('peer.company_name', 'ILIKE', $like)
                    ->orWhere('peer.city', 'ILIKE', $like);
            });
        }

        if ($filters['title'] !== '') {
            $this->applyLikeFilter($query, $titleColumns, $filters['title']);
        }

        if ($filters['scope'] !== '') {
            $this->applyLikeFilter($query, $scopeColumns, $filters['scope']);
        }

        if ($filters['preferred_mode'] !== '') {
            $this->applyLikeFilter($query, $preferredModeColumns, $filters['preferred_mode']);
        }

        if ($filters['business_stage'] !== '') {
            $this->applyLikeFilter($query, $businessStageColumns, $filters['business_stage']);
        }

        if ($filters['year_in_operation'] !== '') {
            $this->applyLikeFilter($query, $yearOperationColumns, $filters['year_in_operation'], true);
        }

        if ($filters['collaboration_type'] !== '') {
            $typeInput = $filters['collaboration_type'];

            if (Schema::hasColumn('collaboration_posts', 'collaboration_type')) {
                $query->where('collaboration_posts.collaboration_type', 'ILIKE', '%' . $this->escapeLike($typeInput) . '%');
            } elseif (Schema::hasColumn('collaboration_posts', 'collaboration_type_id')) {
                if (Str::isUuid($typeInput)) {
                    $query->where('collaboration_posts.collaboration_type_id', $typeInput);
                } elseif (class_exists(CollaborationType::class)) {
                    $typeIds = CollaborationType::query()
                        ->where('name', 'ILIKE', '%' . $this->escapeLike($typeInput) . '%')
                        ->orWhere('slug', 'ILIKE', '%' . $this->escapeLike($typeInput) . '%')
                        ->pluck('id')
                        ->all();

                    if ($typeIds === []) {
                        $query->whereRaw('1 = 0');
                    } else {
                        $query->whereIn('collaboration_posts.collaboration_type_id', $typeIds);
                    }
                }
            }
        }

        if (! empty($filters['circle_id'])) {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'collaboration_posts.user_id')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        try {
            if ($filters['from'] !== '') {
                $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
            }
        } catch (\Throwable $exception) {
            // ignore invalid date
        }

        try {
            if ($filters['to'] !== '') {
                $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
            }
        } catch (\Throwable $exception) {
            // ignore invalid date
        }
    }

    private function applyLikeFilter(Builder $query, array $columns, string $value, bool $castAsText = false): void
    {
        if ($columns === []) {
            return;
        }

        $query->where(function (Builder $inner) use ($columns, $value, $castAsText) {
            $this->applyLikeAny($inner, $columns, $value, false, $castAsText);
        });
    }

    private function applyLikeAny(Builder $query, array $columns, string $value, bool $useOr = false, bool $castAsText = false): void
    {
        if ($columns === []) {
            return;
        }

        foreach (array_values($columns) as $index => $column) {
            $method = $this->pickMethod($useOr, $index);
            if ($castAsText) {
                $query->{$method . 'Raw'}("CAST({$column} AS TEXT) ILIKE ?", ['%' . $this->escapeLike($value) . '%']);
                continue;
            }

            $query->{$method}($column, 'ILIKE', '%' . $this->escapeLike($value) . '%');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    private function pickMethod(bool $useOr, int $index): string
    {
        return $index === 0 ? ($useOr ? 'orWhere' : 'where') : 'orWhere';
    }

    private function existingPostColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('collaboration_posts', $column)));
    }

    private function existingUserColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('users', $column)));
    }
}
