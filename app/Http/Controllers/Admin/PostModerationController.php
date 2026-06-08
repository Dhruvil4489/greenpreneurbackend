<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Impact;
use App\Models\Post;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PostModerationController extends Controller
{
    private function ensureGlobalAdmin(): void
    {
        $admin = Auth::guard('admin')->user();

        if (! AdminAccess::isGlobalAdmin($admin)) {
            abort(403);
        }
    }

    private function ensureGlobalAdminOrIndustryDirector(): void
    {
        $admin = Auth::guard('admin')->user();

        if (! AdminAccess::isGlobalAdmin($admin) && ! app(IndustryScopeService::class)->isIndustryDirector($admin)) {
            abort(403);
        }
    }

    public function index(Request $request): View
    {
        $this->ensureGlobalAdminOrIndustryDirector();
        $admin = Auth::guard('admin')->user();
        $industryScope = app(IndustryScopeService::class);

        $circleId = $request->query('circle_id', 'all');

        $filters = [
            'active' => $request->input('active', 'active'),
            'visibility' => $request->input('visibility'),
            'moderation_status' => $request->input('moderation_status'),
            'search' => $request->input('search'),
        ];

        $peer = $request->query('peer');
        $inlineVisibility = $request->query('inline_visibility', 'any');
        $inlineModerationStatus = $request->query('inline_moderation_status', 'any');
        $inlineActive = $request->query('inline_active', 'any');
        $media = $request->query('media', 'any');
        $query = Post::withTrashed()
            ->with(['user', 'circle'])
            ->when($circleId !== 'all' && filled($circleId), fn ($q) => $q->where('circle_id', $circleId));


        $industryScope->applyToActivityQuery($query, $admin, ['posts.user_id']);

        if (filled($filters['visibility']) && $filters['visibility'] !== 'any') {
            $query->where('posts.visibility', $filters['visibility']);
        }

        if (filled($filters['moderation_status']) && $filters['moderation_status'] !== 'any') {
            $query->where('posts.moderation_status', $filters['moderation_status']);
        }

        if (filled($inlineVisibility) && $inlineVisibility !== 'any') {
            $query->where('posts.visibility', $inlineVisibility);
        }

        if (filled($inlineModerationStatus) && $inlineModerationStatus !== 'any') {
            $query->where('posts.moderation_status', $inlineModerationStatus);
        }


        if ($filters['search']) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('posts.content_text', 'ILIKE', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('display_name', 'ILIKE', $search)
                            ->orWhere('name', 'ILIKE', $search)
                            ->orWhere('first_name', 'ILIKE', $search)
                            ->orWhere('last_name', 'ILIKE', $search);
                    });
            });
        }


        if (filled($peer)) {
            $peerQuery = '%' . $peer . '%';
            $query->whereHas('user', function ($userQuery) use ($peerQuery) {
                $userQuery->where(function ($subQuery) use ($peerQuery) {
                    $subQuery->where('name', 'ILIKE', $peerQuery)
                        ->orWhere('display_name', 'ILIKE', $peerQuery)
                        ->orWhere('first_name', 'ILIKE', $peerQuery)
                        ->orWhere('last_name', 'ILIKE', $peerQuery)
                        ->orWhere('company', 'ILIKE', $peerQuery)
                        ->orWhere('company_name', 'ILIKE', $peerQuery)
                        ->orWhere('business_name', 'ILIKE', $peerQuery)
                        ->orWhere('organization', 'ILIKE', $peerQuery)
                        ->orWhere('city', 'ILIKE', $peerQuery)
                        ->orWhere('current_city', 'ILIKE', $peerQuery)
                        ->orWhere('location_city', 'ILIKE', $peerQuery);
                });
            });
        }

        if ($media === 'has') {
            $query->where(function ($subQuery) {
                $subQuery->whereNotNull('posts.media')
                    ->whereRaw("NULLIF(TRIM(posts.media::text), '') IS NOT NULL")
                    ->whereRaw("posts.media::text NOT IN ('[]', '{}', 'null')");
            });
        }

        if ($media === 'none') {
            $query->where(function ($subQuery) {
                $subQuery->whereNull('posts.media')
                    ->orWhereRaw("TRIM(posts.media::text) = ''")
                    ->orWhereRaw("posts.media::text IN ('[]', '{}', 'null')");
            });
        }

        $activeFilter = $this->resolveActiveFilter($filters['active'] ?? 'active', $inlineActive);

        if ($activeFilter === 'inactive') {
            $query->where(function ($subQuery) {
                $subQuery->whereNotNull('posts.deleted_at')
                    ->orWhere('posts.is_deleted', true);
            });
        } elseif ($activeFilter === 'active') {
            $query->whereNull('posts.deleted_at')
                ->where(function ($subQuery) {
                    $subQuery->where('posts.is_deleted', false)
                        ->orWhereNull('posts.is_deleted');
                });
        }

        $impactQuery = Impact::query()
            ->with(['user'])
            ->where('status', 'approved');

        $industryScope->applyToActivityQuery($impactQuery, $admin, ['impacts.user_id']);

        if ($circleId !== 'all' && filled($circleId)) {
            $impactQuery->whereRaw('1 = 0');
        }

        $visibilityFilters = [
            $filters['visibility'] ?? null,
            $inlineVisibility !== 'any' ? $inlineVisibility : null,
        ];

        foreach ($visibilityFilters as $visibilityFilter) {
            if (filled($visibilityFilter) && $visibilityFilter !== 'public') {
                $impactQuery->whereRaw('1 = 0');
            }
        }

        $moderationFilters = [
            $filters['moderation_status'] ?? null,
            $inlineModerationStatus !== 'any' ? $inlineModerationStatus : null,
        ];

        foreach ($moderationFilters as $moderationFilter) {
            if (filled($moderationFilter) && $moderationFilter !== 'approved') {
                $impactQuery->whereRaw('1 = 0');
            }
        }

        if ($activeFilter === 'inactive') {
            $impactQuery->whereNull('timeline_posted_at');
        } elseif ($activeFilter === 'active') {
            $impactQuery->whereNotNull('timeline_posted_at');
        }

        if ($media === 'has') {
            $impactQuery->whereRaw('1 = 0');
        }

        if ($filters['search']) {
            $search = '%' . $filters['search'] . '%';
            $impactQuery->where(function ($subQuery) use ($search) {
                $subQuery->where('story_to_share', 'ILIKE', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('display_name', 'ILIKE', $search)
                            ->orWhere('first_name', 'ILIKE', $search)
                            ->orWhere('last_name', 'ILIKE', $search)
                            ->orWhere('company_name', 'ILIKE', $search);
                    });
            });
        }

        if (filled($peer)) {
            $peerQuery = '%' . $peer . '%';
            $impactQuery->whereHas('user', function ($userQuery) use ($peerQuery) {
                $userQuery->where(function ($subQuery) use ($peerQuery) {
                    $subQuery->where('display_name', 'ILIKE', $peerQuery)
                        ->orWhere('first_name', 'ILIKE', $peerQuery)
                        ->orWhere('last_name', 'ILIKE', $peerQuery)
                        ->orWhere('company_name', 'ILIKE', $peerQuery)
                        ->orWhere('city', 'ILIKE', $peerQuery);
                });
            });
        }

        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $postRows = (clone $query)->toBase()->selectRaw("posts.id as id, posts.created_at as sort_at, 'post' as source_type");
        $impactRows = (clone $impactQuery)->toBase()->selectRaw("impacts.id as id, COALESCE(impacts.timeline_posted_at, impacts.approved_at, impacts.created_at) as sort_at, 'impact' as source_type");

        $union = $postRows->unionAll($impactRows);
        $orderedRows = DB::query()->fromSub($union, 'timeline_rows')->orderByDesc('sort_at');

        $total = (clone $orderedRows)->count();
        $pageRows = (clone $orderedRows)->forPage($page, $perPage)->get();

        $postIds = $pageRows->where('source_type', 'post')->pluck('id')->values()->all();
        $impactIds = $pageRows->where('source_type', 'impact')->pluck('id')->values()->all();

        $postsById = Post::query()
            ->with(['user', 'circle', 'collaborationPost.acceptedByUser'])
            ->whereIn('id', $postIds)
            ->get()
            ->keyBy(fn (Post $post) => (string) $post->id);

        $impactsById = Impact::query()
            ->with(['user'])
            ->whereIn('id', $impactIds)
            ->get()
            ->keyBy(fn (Impact $impact) => (string) $impact->id);

        $items = $this->hydrateTimelineRows($pageRows, $postsById, $impactsById);

        $posts = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $visibilities = ['public', 'connections', 'private'];
        $moderationOptions = [
            'any' => 'Any',
            'pending' => 'Pending',
            'complaint' => 'Complaint',
            'open' => 'Open',
            'rejected' => 'Rejected',
        ];

        $circleOptionsQuery = Circle::query()->orderBy('name');
        if ($industryScope->isIndustryDirector($admin)) {
            $circleIds = $industryScope->circleIdsForAdmin($admin);
            $circleOptionsQuery->when($circleIds !== [], fn ($q) => $q->whereIn('id', $circleIds), fn ($q) => $q->whereRaw('1 = 0'));
        }
        $circles = $circleOptionsQuery->get(['id', 'name']);

        return view('admin.posts.index', [
            'posts' => $posts,
            'filters' => $filters,
            'visibilities' => $visibilities,
            'moderationOptions' => $moderationOptions,
            'circles' => $circles,
            'circleId' => $circleId,
            'peer' => $peer,
            'inlineVisibility' => $inlineVisibility,
            'inlineModerationStatus' => $inlineModerationStatus,
            'inlineActive' => $inlineActive,
            'media' => $media,
        ]);
    }

    private function resolveActiveFilter(?string $active, ?string $inlineActive): string
    {
        if ($inlineActive === 'yes') {
            return 'active';
        }

        if ($inlineActive === 'no') {
            return 'inactive';
        }

        return match ($active) {
            'active' => 'active',
            'deactivated' => 'inactive',
            default => 'all',
        };
    }

    private function hydrateTimelineRows($pageRows, Collection $postsById, Collection $impactsById): Collection
    {
        return collect($pageRows)->map(function ($row) use ($postsById, $impactsById) {
            if ((string) $row->source_type === 'post') {
                $post = $postsById->get((string) $row->id);

                if (! $post) {
                    return null;
                }

                $post->timeline_item_type = 'post';

                return $post;
            }

            $impact = $impactsById->get((string) $row->id);

            if (! $impact) {
                return null;
            }

            return (object) [
                'id' => (string) $impact->id,
                'timeline_item_type' => 'impact',
                'source_type' => 'impact',
                'user' => $impact->user,
                'circle' => null,
                'visibility' => 'public',
                'moderation_status' => 'approved',
                'deleted_at' => $impact->timeline_posted_at ? null : $impact->updated_at,
                'is_active' => ! is_null($impact->timeline_posted_at),
                'content_text' => (string) $impact->story_to_share,
                'media' => [],
                'timeline_posted_at' => $impact->timeline_posted_at,
                'created_at' => $impact->timeline_posted_at ?? $impact->approved_at ?? $impact->created_at,
            ];
        })->filter()->values();
    }

    public function show(string $postId): View
    {
        $this->ensureGlobalAdminOrIndustryDirector();
        $admin = Auth::guard('admin')->user();

        $post = Post::withTrashed()
            ->with([
                'user:id,display_name,first_name,last_name',
                'circle:id,name',
            ])
            ->findOrFail($postId);

        if (! app(IndustryScopeService::class)->userInScope($admin, (string) $post->user_id)) {
            abort(403);
        }

        return view('admin.posts.show', [
            'post' => $post,
        ]);
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        DB::transaction(function () use ($post): void {
            if (array_key_exists('is_deleted', $post->getAttributes())) {
                $post->is_deleted = true;
                $post->save();
            }

            $post->delete();
        });

        return redirect()->back()->with('success', 'Post removed successfully.');
    }

    public function deactivate(Post $post): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        DB::transaction(function () use ($post): void {
            if (Schema::hasColumn('posts', 'is_active')) {
                $post->setAttribute('is_active', false);
            }

            if (array_key_exists('is_deleted', $post->getAttributes())) {
                $post->is_deleted = true;
            }

            $post->save();
        });

        return redirect()->back()->with('success', 'Post deactivated successfully.');
    }

    public function deactivateImpact(string $impactId): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $impact = Impact::query()->find($impactId);

        if (! $impact) {
            return redirect()->back()->withErrors('Impact post not found.');
        }

        if ($impact->status !== 'approved') {
            return redirect()->back()->withErrors('Only approved impact posts can be deactivated from All Posts.');
        }

        $impact->timeline_posted_at = null;
        $impact->save();

        return redirect()->back()->with('success', 'Impact post deactivated successfully.');
    }

    public function activateImpact(string $impactId): RedirectResponse
    {
        $this->ensureGlobalAdmin();

        $impact = Impact::query()->find($impactId);

        if (! $impact) {
            return redirect()->back()->withErrors('Impact post not found.');
        }

        if ($impact->status !== 'approved') {
            return redirect()->back()->withErrors('Only approved impact posts can be activated from All Posts.');
        }

        $impact->timeline_posted_at = $impact->timeline_posted_at ?? now();
        $impact->save();

        return redirect()->back()->with('success', 'Impact post activated successfully.');
    }

    public function restore(string $postId): RedirectResponse
    {
        $this->ensureGlobalAdminOrIndustryDirector();
        $admin = Auth::guard('admin')->user();

        $post = Post::withTrashed()->findOrFail($postId);

        DB::transaction(function () use ($post): void {
            if (method_exists($post, 'restore')) {
                $post->restore();
            }

            if (array_key_exists('is_deleted', $post->getAttributes())) {
                $post->is_deleted = false;
                $post->save();
            }
        });

        return redirect()->back()->with('success', 'Post restored successfully.');
    }
}
