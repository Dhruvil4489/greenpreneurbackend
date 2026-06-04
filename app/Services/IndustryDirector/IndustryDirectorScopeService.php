<?php

namespace App\Services\IndustryDirector;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IndustryDirectorScopeService
{
    public function assignedIndustryIdsForAdmin(string $adminUserId): array
    {
        if ($adminUserId === '' || ! Schema::hasTable('industry_director_assignments')) {
            return [];
        }

        return DB::table('industry_director_assignments')
            ->where('admin_user_id', $adminUserId)
            ->where('is_active', true)
            ->pluck('industry_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function assignedIndustryIdForAdmin($adminUserId): ?string
    {
        return $this->assignedIndustryIdsForAdmin((string) $adminUserId)[0] ?? null;
    }

    public function selectedIndustryId(Request $request): ?string
    {
        Log::info('IDE Selected Industry Debug', [
            'admin_user_id' => Auth::guard('admin')->id(),
            'request_industry_id' => $request->input('industry_id'),
            'request_selected_industry_id' => $request->input('selected_industry_id'),
            'session_selected_industry_id' => $request->session()->get('industry_director.selected_industry_id'),
            'route' => $request->route()?->getName(),
            'url' => $request->fullUrl(),
        ]);

        $adminUserId = (string) Auth::guard('admin')->id();
        if ($adminUserId === '') {
            return null;
        }

        $requestedIndustryId = (string) ($request->input('selected_industry_id') ?: $request->input('industry_id') ?: '');
        if ($requestedIndustryId !== '') {
            $this->ensureAdminCanAccessIndustry($adminUserId, $requestedIndustryId);
            $this->setSelectedIndustry($requestedIndustryId);
            return $requestedIndustryId;
        }

        $sessionIndustryId = (string) $request->session()->get('industry_director.selected_industry_id', '');
        if ($sessionIndustryId !== '') {
            if (in_array($sessionIndustryId, $this->assignedIndustryIdsForAdmin($adminUserId), true)) {
                return $sessionIndustryId;
            }

            $request->session()->forget('industry_director.selected_industry_id');
        }

        $fallbackIndustryId = $this->assignedIndustryIdForAdmin($adminUserId);
        if ($fallbackIndustryId !== null) {
            $this->setSelectedIndustry($fallbackIndustryId);
        }

        return $fallbackIndustryId;
    }

    public function setSelectedIndustry(string $industryId): void
    {
        session(['industry_director.selected_industry_id' => $industryId]);
    }

    public function ensureAdminCanAccessIndustry(string $adminUserId, string $industryId): void
    {
        abort_unless(
            in_array($industryId, $this->assignedIndustryIdsForAdmin($adminUserId), true),
            403,
            'You are not assigned to this industry.'
        );
    }

    public function industryIdsForAdmin($adminUserId): array
    {
        $assignedIndustryId = $this->assignedIndustryIdForAdmin((string) $adminUserId);

        return $assignedIndustryId ? $this->industryIdsForFilter($assignedIndustryId) : [];
    }

    public function industryIdsForFilter(string $selectedIndustryId): array
    {
        $selectedIndustryId = trim($selectedIndustryId);
        if ($selectedIndustryId === '' || ! Schema::hasTable('industries')) {
            return [];
        }

        $ids = collect([$selectedIndustryId]);
        $frontier = collect([$selectedIndustryId]);

        while ($frontier->isNotEmpty() && Schema::hasColumn('industries', 'parent_id')) {
            $children = DB::table('industries')
                ->whereIn('parent_id', $frontier->values()->all())
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->filter(fn (string $id) => $id !== '');

            $new = $children->diff($ids)->values();
            if ($new->isEmpty()) {
                break;
            }

            $ids = $ids->merge($new)->unique()->values();
            $frontier = $new;
        }

        return $ids->unique()->values()->all();
    }

    public function applyUsersScope($query, string $selectedIndustryId)
    {
        return $this->applyUsersIndustryColumns($query, $this->industryIdsForFilter($selectedIndustryId), 'users');
    }

    public function memberIds(string $selectedIndustryId): array
    {
        $industryIdsForFilter = $this->industryIdsForFilter($selectedIndustryId);
        $directMemberIds = $this->applyUsersScope(User::query()->select('users.id'), $selectedIndustryId)
            ->pluck('users.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
        $mappingMemberIds = $this->memberIdsFromMappingView($selectedIndustryId, $industryIdsForFilter);
        $memberIds = collect($mappingMemberIds)
            ->merge($directMemberIds)
            ->unique()
            ->values()
            ->all();

        Log::info('IDE Scope Debug', [
            'admin_user_id' => Auth::guard('admin')->id(),
            'assigned_industry_id' => $selectedIndustryId,
            'mapping_member_count' => count($mappingMemberIds),
            'direct_member_count' => count($directMemberIds),
            'matched_member_ids' => $memberIds,
            'matched_member_count' => count($memberIds),
        ]);

        Log::info('IDE Scope Result', [
            'selected_industry_id' => $selectedIndustryId,
            'allowed_industry_ids' => $this->assignedIndustryIdsForAdmin((string) Auth::guard('admin')->id()),
            'industry_ids_for_filter' => $industryIdsForFilter,
            'matched_member_count' => count($memberIds),
            'matched_member_ids' => $memberIds,
        ]);

        return $memberIds;
    }

    public function memberIdsForIndustry($adminUserId): array
    {
        return $this->memberIdsForAdminIndustry((string) $adminUserId);
    }

    public function memberIdsForAdminIndustry($adminUserId): array
    {
        $selectedIndustryId = $this->selectedIndustryId(request());

        if (! $selectedIndustryId) {
            return [];
        }

        return $this->memberIds($selectedIndustryId);
    }

    public function applyPeersScope($query, $adminUserId)
    {
        $selectedIndustryId = $this->selectedIndustryId(request());

        if (! $selectedIndustryId) {
            return $query->whereRaw('1 = 0');
        }

        $memberIds = $this->memberIds($selectedIndustryId);

        return $memberIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('users.id', $memberIds);
    }

    public function applyPostsScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), ['posts.user_id', 'user_id']);
    }

    public function applyActivitiesScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), [
            'from_user_id',
            'to_user_id',
            'user_id',
            'initiator_user_id',
            'peer_user_id',
            'member_id',
            'recommender_id',
            'recommended_user_id',
            'visitor_id',
            'created_by',
            'invited_by_user_id',
        ]);
    }

    public function applyPendingRequestsScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), ['user_id', 'requester_id', 'member_id']);
    }

    public function applyLeadsScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), ['user_id', 'created_by', 'member_id']);
    }

    public function applyCirclesScope($query, string $selectedIndustryId)
    {
        $industryIds = $this->cleanIds($this->industryIdsForFilter($selectedIndustryId));

        if ($industryIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($scope) use ($industryIds, $selectedIndustryId): void {
            $hasCondition = false;

            foreach (['industry_id', 'category_id', 'industry_category_id', 'circle_category_id'] as $column) {
                $hasCondition = $this->orWhereColumnIn($scope, 'circles', $column, $industryIds) || $hasCondition;
            }

            $hasCondition = $this->orWhereJsonContainsAny($scope, 'circles', 'industry_tags', $this->industryScopeValues($industryIds)) || $hasCondition;

            if (! $hasCondition && Schema::hasTable('circle_members') && Schema::hasColumn('circle_members', 'circle_id') && Schema::hasColumn('circle_members', 'user_id')) {
                $memberIds = $this->memberIds($selectedIndustryId);

                if ($memberIds !== []) {
                    $scope->orWhereExists(function ($subQuery) use ($memberIds): void {
                        $subQuery->selectRaw('1')
                            ->from('circle_members as ide_circle_members')
                            ->whereColumn('ide_circle_members.circle_id', 'circles.id')
                            ->whereIn('ide_circle_members.user_id', $memberIds);
                    });
                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });
    }

    public function applyCoinsScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), ['coins_ledger.user_id', 'user_id']);
    }

    public function applyLifeImpactScope($query, string $selectedIndustryId)
    {
        return $this->applyUserIdScope($query, $this->memberIds($selectedIndustryId), ['life_impact_histories.user_id', 'user_id', 'member_id']);
    }

    public function isIndustryDirector(?AdminUser $adminUser): bool
    {
        if (! $adminUser) {
            return false;
        }

        $adminUser->loadMissing('roles:id,key');

        return $adminUser->roles->pluck('key')->contains('industry_director')
            && $this->assignedIndustryIdForAdmin($adminUser->id) !== null;
    }

    public function memberIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        return $this->memberIdsForAdminIndustry($adminUser->id);
    }

    public function circleIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        $selectedIndustryId = $this->selectedIndustryId(request());

        return $selectedIndustryId ? $this->circleIdsForIndustryIds($this->industryIdsForFilter($selectedIndustryId)) : [];
    }

    public function circleIdsForIndustryIds(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === [] || ! Schema::hasTable('circles')) {
            return [];
        }

        $query = DB::table('circles')->select('circles.id')->distinct();
        $this->applyCirclesScopeToIndustryIds($query, $industryIds);

        if (Schema::hasColumn('circles', 'deleted_at')) {
            $query->whereNull('circles.deleted_at');
        }

        return $query->pluck('circles.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function applyToUsersQuery($query, ?AdminUser $adminUser, string $userColumn = 'users.id'): void
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return;
        }

        if ($userColumn === 'users.id') {
            $this->applyPeersScope($query, $adminUser->id);
            return;
        }

        $this->applyUserIdScope($query, $this->memberIdsForAdminIndustry($adminUser->id), [$userColumn]);
    }

    public function applyToActivityQuery($query, ?AdminUser $adminUser, array $userColumns): void
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return;
        }

        $this->applyUserIdScope($query, $this->memberIdsForAdminIndustry($adminUser->id), $userColumns);
    }

    public function userInScope(?AdminUser $adminUser, string $userId): bool
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return true;
        }

        return in_array((string) $userId, $this->memberIdsForAdminIndustry($adminUser->id), true);
    }

    public function circleInScope(?AdminUser $adminUser, string $circleId): bool
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return true;
        }

        return in_array((string) $circleId, $this->circleIdsForAdmin($adminUser), true);
    }


    private function memberIdsFromMappingView(string $selectedIndustryId, array $industryIds): array
    {
        if (! Schema::hasTable('industry_director_user_mappings')) {
            return [];
        }

        $adminUserId = (string) Auth::guard('admin')->id();
        $industryIds = $this->cleanIds($industryIds);

        $query = DB::table('industry_director_user_mappings')
            ->select('user_id')
            ->distinct()
            ->where(function ($scope) use ($adminUserId, $industryIds): void {
                $hasCondition = false;

                if ($adminUserId !== '') {
                    $adminIds = $this->idsForColumn('industry_director_user_mappings', 'industry_director_id', [$adminUserId]);
                    if ($adminIds !== []) {
                        $scope->orWhereIn('industry_director_id', $adminIds);
                        $hasCondition = true;
                    }
                }

                $mainCategoryIds = $this->idsForColumn('industry_director_user_mappings', 'main_category_id', $industryIds);
                if ($mainCategoryIds !== []) {
                    $scope->orWhereIn('main_category_id', $mainCategoryIds);
                    $hasCondition = true;
                }

                $subCategoryIds = $this->idsForColumn('industry_director_user_mappings', 'sub_category_id', $industryIds);
                if ($subCategoryIds !== []) {
                    $scope->orWhereIn('sub_category_id', $subCategoryIds);
                    $hasCondition = true;
                }

                if (! $hasCondition) {
                    $scope->whereRaw('1 = 0');
                }
            });

        return $query->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function applyUsersIndustryColumns($query, array $industryIds, string $table)
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === []) {
            $query->whereRaw('1 = 0');
            return $query;
        }

        $query->where(function ($scope) use ($table, $industryIds): void {
            $hasCondition = false;

            foreach ([
                'business_category_main_id',
                'business_category_sub_id',
                'visitor_business_category_main_id',
                'visitor_business_category_sub_id',
                'active_circle_id',
                'circle_id',
            ] as $column) {
                $hasCondition = $this->orWhereColumnIn($scope, $table, $column, $industryIds) || $hasCondition;
            }

            $hasCondition = $this->orWhereJsonContainsAny($scope, $table, 'industry_tags', $industryIds) || $hasCondition;
            $hasCondition = $this->orWhereJsonContainsAny($scope, $table, 'target_business_categories', $industryIds) || $hasCondition;

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function applyCirclesScopeToIndustryIds($query, array $industryIds)
    {
        $query->where(function ($scope) use ($industryIds): void {
            $hasCondition = false;

            foreach (['industry_id', 'category_id', 'industry_category_id', 'circle_category_id'] as $column) {
                $hasCondition = $this->orWhereColumnIn($scope, 'circles', $column, $industryIds) || $hasCondition;
            }

            $hasCondition = $this->orWhereJsonContainsAny($scope, 'circles', 'industry_tags', $this->industryScopeValues($industryIds)) || $hasCondition;

            if (Schema::hasTable('circle_category_mappings')) {
                $categoryIds = $this->idsForColumn('circle_category_mappings', 'category_id', $industryIds);

                if ($categoryIds !== []) {
                    $scope->orWhereExists(function ($subQuery) use ($categoryIds): void {
                        $subQuery->selectRaw('1')
                            ->from('circle_category_mappings as ide_ccm')
                            ->whereColumn('ide_ccm.circle_id', 'circles.id')
                            ->whereIn('ide_ccm.category_id', $categoryIds);
                    });
                    $hasCondition = true;
                }
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function applyUserIdScope($query, array $memberIds, array $userColumns)
    {
        $memberIds = $this->cleanIds($memberIds);

        if ($memberIds === []) {
            $query->whereRaw('1 = 0');
            return $query;
        }

        $query->where(function ($scope) use ($userColumns, $memberIds): void {
            $hasCondition = false;
            $table = method_exists($scope, 'from') ? (string) ($scope->from ?? '') : '';

            foreach ($userColumns as $userColumn) {
                $userColumn = trim((string) $userColumn);
                if ($userColumn === '') {
                    continue;
                }

                if (! str_contains($userColumn, '.') && $table !== '' && ! Schema::hasColumn($table, $userColumn)) {
                    continue;
                }

                $scope->orWhereIn($userColumn, $memberIds);
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    private function orWhereColumnIn($query, string $table, string $column, array $ids): bool
    {
        $compatibleIds = $this->idsForColumn($table, $column, $ids);

        if ($compatibleIds === []) {
            return false;
        }

        $query->orWhereIn("{$table}.{$column}", $compatibleIds);

        return true;
    }

    private function industryScopeValues(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);
        $values = collect($industryIds);

        if ($industryIds !== [] && Schema::hasTable('industries')) {
            $columns = ['id'];

            foreach (['name', 'slug'] as $column) {
                if (Schema::hasColumn('industries', $column)) {
                    $columns[] = $column;
                }
            }

            DB::table('industries')
                ->select($columns)
                ->whereIn('id', $industryIds)
                ->get()
                ->each(function ($industry) use ($columns, $values): void {
                    foreach ($columns as $column) {
                        $value = trim((string) ($industry->{$column} ?? ''));

                        if ($value !== '') {
                            $values->push($value);
                        }
                    }
                });
        }

        return $values
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function orWhereJsonContainsAny($query, string $table, string $column, array $ids): bool
    {
        if (! Schema::hasColumn($table, $column)) {
            return false;
        }

        $hasCondition = false;
        $qualifiedColumn = "{$table}.{$column}";

        foreach ($this->cleanIds($ids) as $id) {
            $query->orWhereJsonContains($qualifiedColumn, (string) $id)
                ->orWhereJsonContains($qualifiedColumn, ['id' => (string) $id])
                ->orWhereJsonContains($qualifiedColumn, [['id' => (string) $id]])
                ->orWhereRaw("CAST({$qualifiedColumn} AS TEXT) LIKE ?", ['%'.(string) $id.'%']);
            $hasCondition = true;

            if (ctype_digit((string) $id)) {
                $query->orWhereJsonContains($qualifiedColumn, (int) $id)
                    ->orWhereJsonContains($qualifiedColumn, ['id' => (int) $id])
                    ->orWhereJsonContains($qualifiedColumn, [['id' => (int) $id]]);
            }
        }

        return $hasCondition;
    }

    private function idsForColumn(string $table, string $column, array $ids): array
    {
        if (! Schema::hasColumn($table, $column)) {
            return [];
        }

        $columnType = $this->columnType($table, $column);
        $ids = $this->cleanIds($ids);

        if (str_contains($columnType, 'int')) {
            return array_values(array_filter($ids, fn (string $id) => ctype_digit($id)));
        }

        if (str_contains($columnType, 'uuid')) {
            return array_values(array_filter($ids, fn (string $id) => Str::isUuid($id)));
        }

        return $ids;
    }

    private function columnType(string $table, string $column): string
    {
        try {
            return strtolower((string) DB::table('information_schema.columns')
                ->where('table_schema', $this->schemaName())
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type'));
        } catch (\Throwable) {
            try {
                return strtolower((string) Schema::getColumnType($table, $column));
            } catch (\Throwable) {
                return '';
            }
        }
    }

    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $ids,
        ), fn (string $id) => $id !== '')));
    }

    private function schemaName(): string
    {
        $schema = (string) config('database.connections.' . config('database.default') . '.search_path', 'public');
        $schema = trim((string) explode(',', $schema)[0], " \t\n\r\0\x0B\"");

        return $schema !== '' ? $schema : 'public';
    }
}
