<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\User;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LifeImpactController extends Controller
{
    private const CATEGORIES = [
        'business_deals' => ['label' => 'Business Deals', 'points' => 5, 'aliases' => ['business_deal', 'business_deals', 'businessdeal']],
        'referrals' => ['label' => 'Referrals', 'points' => 1, 'aliases' => ['referral', 'referrals']],
        'testimonials' => ['label' => 'Testimonials', 'points' => 5, 'aliases' => ['testimonial', 'testimonials']],
        'mentorship' => ['label' => 'Mentorship', 'points' => 1, 'aliases' => ['mentorship', 'mentor', 'mentoring']],
        'joint_venture' => ['label' => 'Joint Venture', 'points' => 1, 'aliases' => ['joint_venture', 'joint_ventures', 'jointventure']],
        'knowledge_sharing' => ['label' => 'Knowledge Sharing', 'points' => 1, 'aliases' => ['knowledge_sharing', 'knowledge_share', 'knowledge']],
        'problem_solving' => ['label' => 'Problem Solving', 'points' => 1, 'aliases' => ['problem_solving', 'problem_solve', 'problem']],
        'vendor_connect' => ['label' => 'Vendor Connect', 'points' => 1, 'aliases' => ['vendor_connect', 'vendor_connection', 'vendor']],
        'funding_access' => ['label' => 'Funding Access', 'points' => 1, 'aliases' => ['funding_access', 'funding', 'fund_access']],
        'visibility_pr' => ['label' => 'Visibility & PR', 'points' => 1, 'aliases' => ['visibility_pr', 'visibility_and_pr', 'visibility', 'pr']],
        'emotional_support' => ['label' => 'Emotional Support', 'points' => 1, 'aliases' => ['emotional_support', 'emotional']],
        'execution_support' => ['label' => 'Execution Support', 'points' => 1, 'aliases' => ['execution_support', 'execution']],
    ];

    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $dateFilterActive = $this->hasDateFilter($filters);

        $members = $this->membersQuery($filters)
            ->orderByDesc('total_life_impacted_sort')
            ->orderBy('users.display_name')
            ->paginate($perPage)
            ->appends($request->query());

        return view('admin.life-impact.index', [
            'members' => $members,
            'filters' => $filters,
            'circles' => $this->circleOptions(auth('admin')->user()),
            'categories' => self::CATEGORIES,
            'impactStats' => $this->impactStatsByUserId($members->pluck('id')->all(), $filters),
            'summary' => $this->summaryStats($filters),
            'dateFilterActive' => $dateFilterActive,
            'quickDateRanges' => $this->quickDateRanges(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);

        $query = $this->membersQuery($filters)
            ->orderByDesc('total_life_impacted_sort')
            ->orderBy('users.display_name');

        $filename = 'life_impact_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query, $filters): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_merge(['Peer Name', 'Total Life Impacted'], array_column(self::CATEGORIES, 'label')));

            $dateFilterActive = $this->hasDateFilter($filters);

            $query->chunk(500, function ($members) use ($handle, $filters, $dateFilterActive): void {
                $stats = $this->impactStatsByUserId($members->pluck('id')->all(), $filters);

                foreach ($members as $member) {
                    $rowStats = $stats[(string) $member->id] ?? [];
                    $row = [
                        $member->adminName(),
                        $dateFilterActive
                            ? (int) ($rowStats['total_life_impacted'] ?? 0)
                            : (int) ($member->life_impacted_count ?? 0),
                    ];

                    foreach (array_keys(self::CATEGORIES) as $key) {
                        $row[] = (int) ($rowStats[$key] ?? 0);
                    }

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function history(User $member, Request $request, ?string $category = null): View
    {
        $this->ensureMemberInScope((string) $member->id, auth('admin')->user());

        $requestedCategory = $category ?: (string) $request->query('category', '');
        $activeCategory = $requestedCategory && array_key_exists($requestedCategory, self::CATEGORIES) ? $requestedCategory : null;
        $filters = $this->historyFilters($request);
        $items = $this->historyQuery($member, $filters, $activeCategory)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.life-impact.history', [
            'member' => $member->loadMissing(['circleMembers' => function ($circleMembersQuery) {
                $circleMembersQuery->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->orderByDesc('joined_at')
                    ->with(['circle:id,name']);
            }]),
            'items' => $items,
            'filters' => $filters,
            'activeCategory' => $activeCategory,
            'activeCategoryLabel' => $activeCategory ? self::CATEGORIES[$activeCategory]['label'] : null,
            'categories' => self::CATEGORIES,
        ]);
    }

    private function membersQuery(array $filters): Builder
    {
        $hasUsersName = Schema::hasColumn('users', 'name');
        $hasUsersCompany = Schema::hasColumn('users', 'company');
        $hasUsersBusinessName = Schema::hasColumn('users', 'business_name');

        $query = User::query()
            ->select([
                'users.id',
                'users.email',
                'users.first_name',
                'users.last_name',
                'users.display_name',
                'users.company_name',
                'users.city',
                'users.life_impacted_count',
            ])
            ->with(['circleMembers' => function ($circleMembersQuery) {
                $circleMembersQuery->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->orderByDesc('joined_at')
                    ->with(['circle:id,name']);
            }]);

        if ($this->hasDateFilter($filters)) {
            $query->addSelect([
                'total_life_impacted_sort' => DB::table('life_impact_histories')
                    ->selectRaw('COALESCE(SUM(COALESCE(impact_value, life_impacted, 0)), 0)')
                    ->whereColumn('life_impact_histories.user_id', 'users.id')
                    ->when(trim((string) ($filters['from'] ?? '')) !== '', function ($historyQuery) use ($filters): void {
                        $historyQuery->whereDate('created_at', '>=', $filters['from']);
                    })
                    ->when(trim((string) ($filters['to'] ?? '')) !== '', function ($historyQuery) use ($filters): void {
                        $historyQuery->whereDate('created_at', '<=', $filters['to']);
                    }),
            ]);
        } else {
            $query->addSelect(DB::raw('COALESCE(users.life_impacted_count, 0) as total_life_impacted_sort'));
        }

        AdminCircleScope::applyToUsersQuery($query, auth('admin')->user());
        app(IndustryScopeService::class)->applyToUsersQuery($query, auth('admin')->user());

        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $circleId = (string) ($filters['circle_id'] ?? 'all');

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                $like = "%{$search}%";

                $searchQuery->where('users.display_name', 'ILIKE', $like)
                    ->orWhere('users.first_name', 'ILIKE', $like)
                    ->orWhere('users.last_name', 'ILIKE', $like)
                    ->orWhere('users.company_name', 'ILIKE', $like)
                    ->orWhere('users.city', 'ILIKE', $like);

                if ($hasUsersName) {
                    $searchQuery->orWhere('users.name', 'ILIKE', $like);
                }

                if ($hasUsersCompany) {
                    $searchQuery->orWhere('users.company', 'ILIKE', $like);
                }

                if ($hasUsersBusinessName) {
                    $searchQuery->orWhere('users.business_name', 'ILIKE', $like);
                }
            });
        }

        if ($circleId !== '' && $circleId !== 'all') {
            $query->whereHas('circleMembers', function ($circleMembersQuery) use ($circleId) {
                $circleMembersQuery->where('circle_id', $circleId)
                    ->where('status', 'approved')
                    ->whereNull('deleted_at');
            });
        }

        return $query;
    }

    private function impactStatsByUserId(array $memberIds, array $filters = []): array
    {
        if ($memberIds === []) {
            return [];
        }

        $query = DB::table('life_impact_histories')
            ->whereIn('user_id', $memberIds)
            ->where(function ($q): void {
                $q->whereNull('counted_in_total')
                    ->orWhere('counted_in_total', true);
            })
            ->select(['user_id', 'activity_type', 'impact_category', 'action_key', 'action_label', 'title', 'impact_value', 'life_impacted']);

        $this->applyDateFiltersToHistoryQuery($query, $filters);

        if (Schema::hasColumn('life_impact_histories', 'status')) {
            $query->where(function ($q): void {
                $q->whereNull('status')->orWhere('status', 'approved');
            });
        }

        return $query->get()
            ->reduce(function (array $stats, $history): array {
                $userId = (string) $history->user_id;
                $stats[$userId]['total_life_impacted'] = (int) ($stats[$userId]['total_life_impacted'] ?? 0)
                    + $this->historyImpactValue($history);

                if ($this->isAdminAdjustment($history)) {
                    return $stats;
                }

                $category = $this->resolveCategoryKey($history);

                if ($category !== null) {
                    $stats[$userId][$category] = (int) ($stats[$userId][$category] ?? 0) + self::CATEGORIES[$category]['points'];
                }

                return $stats;
            }, []);
    }

    private function summaryStats(array $filters): array
    {
        $memberIds = $this->membersQuery($filters)->pluck('users.id')->all();
        $summary = [
            'total_life_impacted' => 0,
            'business_deals' => 0,
            'referrals' => 0,
            'testimonials' => 0,
            'other_impact_activities' => 0,
        ];

        if ($memberIds === []) {
            return $summary;
        }

        if ($this->hasDateFilter($filters)) {
            foreach ($this->impactStatsByUserId($memberIds, $filters) as $stats) {
                $summary['total_life_impacted'] += (int) ($stats['total_life_impacted'] ?? 0);
                $summary['business_deals'] += (int) ($stats['business_deals'] ?? 0);
                $summary['referrals'] += (int) ($stats['referrals'] ?? 0);
                $summary['testimonials'] += (int) ($stats['testimonials'] ?? 0);
                $summary['other_impact_activities'] += $this->otherImpactActivitiesTotal($stats);
            }

            return $summary;
        }

        $summary['total_life_impacted'] = (int) $this->membersQuery($filters)->sum(DB::raw('COALESCE(users.life_impacted_count, 0)'));

        foreach ($this->impactStatsByUserId($memberIds, $filters) as $stats) {
            $summary['business_deals'] += (int) ($stats['business_deals'] ?? 0);
            $summary['referrals'] += (int) ($stats['referrals'] ?? 0);
            $summary['testimonials'] += (int) ($stats['testimonials'] ?? 0);
            $summary['other_impact_activities'] += $this->otherImpactActivitiesTotal($stats);
        }

        return $summary;
    }

    private function otherImpactActivitiesTotal(array $stats): int
    {
        return collect(array_keys(self::CATEGORIES))
            ->reject(fn (string $key) => in_array($key, ['business_deals', 'referrals', 'testimonials'], true))
            ->sum(fn (string $key) => (int) ($stats[$key] ?? 0));
    }

    private function historyImpactValue(object $history): int
    {
        if (isset($history->impact_value) && is_numeric($history->impact_value)) {
            return (int) $history->impact_value;
        }

        if (isset($history->life_impacted) && is_numeric($history->life_impacted)) {
            return (int) $history->life_impacted;
        }

        return 0;
    }

    private function isAdminAdjustment(object $history): bool
    {
        return ($history->activity_type ?? null) === 'admin_adjustment'
            || ($history->impact_category ?? null) === 'admin_adjustment'
            || ($history->action_key ?? null) === 'admin_adjustment';
    }

    private function hasDateFilter(array $filters): bool
    {
        return trim((string) ($filters['from'] ?? '')) !== ''
            || trim((string) ($filters['to'] ?? '')) !== '';
    }

    private function applyDateFiltersToHistoryQuery($query, array $filters): void
    {
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));

        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    private function resolveCategoryKey(object $history): ?string
    {
        $tokens = collect([
            $history->action_key ?? null,
            $history->impact_category ?? null,
            $history->activity_type ?? null,
            $history->action_label ?? null,
            $history->title ?? null,
        ])
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value) => $this->normalizeCategoryToken((string) $value))
            ->filter()
            ->values();

        foreach (self::CATEGORIES as $key => $category) {
            foreach ($category['aliases'] as $alias) {
                if ($tokens->contains($alias)) {
                    return $key;
                }
            }
        }

        foreach ($tokens as $token) {
            foreach (self::CATEGORIES as $key => $category) {
                foreach ($category['aliases'] as $alias) {
                    if ($alias !== '' && str_contains($token, $alias)) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeCategoryToken(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }

    private function historyQuery(User $member, array $filters, ?string $category = null)
    {
        $query = DB::table('life_impact_histories')
            ->where('user_id', $member->id)
            ->select([
                'id',
                'user_id',
                'triggered_by_user_id',
                'activity_type',
                'activity_id',
                'impact_value',
                'title',
                'description',
                'life_impacted',
                'counted_in_total',
                'impact_category',
                'action_key',
                'action_label',
                'remarks',
                'created_at',
            ]);

        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $date = trim((string) ($filters['date'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));

        if ($category !== null) {
            $query->where(function ($q): void {
                $q->whereNull('counted_in_total')
                    ->orWhere('counted_in_total', true);
            })
                ->where(function ($q): void {
                    $q->whereNull('activity_type')->orWhere('activity_type', '!=', 'admin_adjustment');
                })
                ->where(function ($q): void {
                    $q->whereNull('impact_category')->orWhere('impact_category', '!=', 'admin_adjustment');
                })
                ->where(function ($q): void {
                    $q->whereNull('action_key')->orWhere('action_key', '!=', 'admin_adjustment');
                });

            if (Schema::hasColumn('life_impact_histories', 'status')) {
                $query->where(function ($q): void {
                    $q->whereNull('status')->orWhere('status', 'approved');
                });
            }

            $this->applyHistoryCategoryFilter($query, $category);
        }

        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($date !== '') {
            $query->whereDate('created_at', '=', $date);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery->where('title', 'ILIKE', $like)
                    ->orWhere('description', 'ILIKE', $like)
                    ->orWhere('remarks', 'ILIKE', $like)
                    ->orWhere('action_label', 'ILIKE', $like)
                    ->orWhere('action_key', 'ILIKE', $like)
                    ->orWhere('impact_category', 'ILIKE', $like)
                    ->orWhere('activity_type', 'ILIKE', $like);
            });
        }

        return $query;
    }

    private function applyHistoryCategoryFilter($query, string $category): void
    {
        $aliases = self::CATEGORIES[$category]['aliases'] ?? [];
        $aliases[] = $category;
        $aliases = collect($aliases)
            ->map(fn ($alias) => $this->normalizeCategoryToken((string) $alias))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $columns = ['action_key', 'impact_category', 'activity_type', 'action_label', 'title'];

        $query->where(function ($categoryQuery) use ($aliases, $columns): void {
            foreach ($columns as $column) {
                foreach ($aliases as $alias) {
                    $categoryQuery->orWhereRaw(
                        "TRIM(BOTH '_' FROM REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '[^a-z0-9]+', '_', 'g')) = ?",
                        [$alias]
                    );

                    if (mb_strlen($alias) > 2) {
                        $categoryQuery->orWhereRaw(
                            "TRIM(BOTH '_' FROM REGEXP_REPLACE(LOWER(COALESCE({$column}, '')), '[^a-z0-9]+', '_', 'g')) LIKE ?",
                            ['%' . $alias . '%']
                        );
                    }
                }
            }
        });
    }

    private function circleOptions($admin)
    {
        $query = Circle::query()->orderBy('name');

        if (app(IndustryScopeService::class)->isIndustryDirector($admin)) {
            $circleIds = app(IndustryScopeService::class)->circleIdsForAdmin($admin);
            $query->when($circleIds !== [], fn ($q) => $q->whereIn('id', $circleIds), fn ($q) => $q->whereRaw('1 = 0'));
        }

        return $query->get(['id', 'name']);
    }

    private function historyFilters(Request $request): array
    {
        return [
            'from' => trim((string) $request->query('from', '')),
            'to' => trim((string) $request->query('to', '')),
            'date' => trim((string) $request->query('date', '')),
            'q' => trim((string) $request->query('q', '')),
        ];
    }

    private function ensureMemberInScope(string $userId, $admin): void
    {
        if (! AdminCircleScope::userInScope($admin, $userId) || ! app(IndustryScopeService::class)->userInScope($admin, $userId)) {
            abort(403);
        }
    }

    private function indexFilters(Request $request): array
    {
        $perPage = $request->integer('per_page') ?: 20;
        $perPage = in_array($perPage, [10, 20, 25, 50, 100], true) ? $perPage : 20;

        $quick = (string) $request->query('quick_date', '');
        $quickRanges = $this->quickDateRanges();
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        if ($quick !== '' && isset($quickRanges[$quick])) {
            $from = $quickRanges[$quick]['from'];
            $to = $quickRanges[$quick]['to'];
        }

        return [
            'q' => trim((string) $request->query('q', $request->query('search', ''))),
            'search' => trim((string) $request->query('q', $request->query('search', ''))),
            'circle_id' => (string) $request->query('circle_id', 'all'),
            'from' => $from,
            'to' => $to,
            'quick_date' => isset($quickRanges[$quick]) ? $quick : '',
            'per_page' => $perPage,
        ];
    }

    private function quickDateRanges(): array
    {
        return [
            'this_month' => [
                'label' => 'This Month',
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->endOfMonth()->toDateString(),
            ],
            'last_month' => [
                'label' => 'Last Month',
                'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'this_year' => [
                'label' => 'This Year',
                'from' => now()->startOfYear()->toDateString(),
                'to' => now()->endOfYear()->toDateString(),
            ],
        ];
    }
}
