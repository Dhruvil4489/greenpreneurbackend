<?php

namespace App\Services\Admin;

use App\Models\AdminUser;
use App\Models\Industry;
use App\Models\IndustryDirectorAssignment;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IndustryScopeService
{
    public function isIndustryDirector(?AdminUser $adminUser): bool
    {
        if (! $adminUser) {
            return false;
        }

        $adminUser->loadMissing('roles:id,key');

        if (! $adminUser->roles->pluck('key')->contains('industry_director')) {
            return false;
        }

        return IndustryDirectorAssignment::query()
            ->where('admin_user_id', $adminUser->id)
            ->where('is_active', true)
            ->exists();
    }

    public function assignedIndustryIdForAdmin(string $adminUserId): ?string
    {
        $industryId = IndustryDirectorAssignment::query()
            ->where('admin_user_id', $adminUserId)
            ->where('is_active', true)
            ->value('industry_id');

        return $industryId ? (string) $industryId : null;
    }

    public function industryIdsForAdmin($adminUserId): array
    {
        $assignedIndustryId = $this->assignedIndustryIdForAdmin((string) $adminUserId);

        if (! $assignedIndustryId) {
            return [];
        }

        $industryIds = [$assignedIndustryId];
        $frontier = [$assignedIndustryId];

        while ($frontier !== [] && Schema::hasColumn('industries', 'parent_id')) {
            $children = Industry::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->filter(fn (string $id) => ! in_array($id, $industryIds, true))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $industryIds = array_values(array_unique([...$industryIds, ...$children]));
            $frontier = $children;
        }

        return $industryIds;
    }

    public function memberIdsForIndustryIds(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === []) {
            return [];
        }

        $industryNames = Industry::query()
            ->whereIn('id', $industryIds)
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();

        $circleIds = $this->circleIdsForIndustryIds($industryIds);

        $query = DB::table('users')->select('users.id')->distinct();

        $query->where(function (QueryBuilder $scope) use ($industryIds, $industryNames, $circleIds): void {
            $hasCondition = false;

            $hasCondition = $this->orWhereColumnIn($scope, 'users', 'industry_id', $industryIds) || $hasCondition;

            if (Schema::hasColumn('users', 'industry_tags')) {
                foreach ([...$industryIds, ...$industryNames] as $industryValue) {
                    $scope->orWhereJsonContains('users.industry_tags', $industryValue);
                    $hasCondition = true;
                }
            }

            foreach ([
                'visitor_business_category_main_id',
                'visitor_business_category_sub_id',
                'business_category_main_id',
                'business_category_sub_id',
                'main_business_category_id',
                'business_category_id',
            ] as $categoryColumn) {
                $hasCondition = $this->orWhereColumnIn($scope, 'users', $categoryColumn, $industryIds) || $hasCondition;
            }

            foreach (['circle_id', 'active_circle_id'] as $circleColumn) {
                $hasCondition = $this->orWhereColumnIn($scope, 'users', $circleColumn, $circleIds) || $hasCondition;
            }

            if ($circleIds !== [] && Schema::hasTable('circle_members')) {
                $scope->orWhereExists(function (QueryBuilder $subQuery) use ($circleIds): void {
                    $subQuery->selectRaw('1')
                        ->from('circle_members as ide_cm')
                        ->whereColumn('ide_cm.user_id', 'users.id')
                        ->whereIn('ide_cm.circle_id', $circleIds)
                        ->when(Schema::hasColumn('circle_members', 'deleted_at'), fn (QueryBuilder $query) => $query->whereNull('ide_cm.deleted_at'));
                });
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $scope->whereRaw('1 = 0');
            }
        });

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('users.deleted_at');
        }

        return $query->pluck('users.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function memberIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        $assignedIndustryId = $this->assignedIndustryIdForAdmin((string) $adminUser->id);
        $industryIds = $this->industryIdsForAdmin((string) $adminUser->id);
        $memberIds = $this->memberIdsForIndustryIds($industryIds);

        Log::info('IDE Scope Debug', [
            'admin_user_id' => (string) $adminUser->id,
            'assigned_industry_id' => $assignedIndustryId,
            'industry_ids' => $industryIds,
            'member_count' => count($memberIds),
        ]);

        return $memberIds;
    }

    public function circleIdsForAdmin(?AdminUser $adminUser): array
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return [];
        }

        return $this->circleIdsForIndustryIds($this->industryIdsForAdmin((string) $adminUser->id));
    }

    public function circleIdsForIndustryIds(array $industryIds): array
    {
        $industryIds = $this->cleanIds($industryIds);

        if ($industryIds === [] || ! Schema::hasTable('circles')) {
            return [];
        }

        $industryNames = Industry::query()
            ->whereIn('id', $industryIds)
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();

        $query = DB::table('circles')->select('circles.id')->distinct();

        $query->where(function (QueryBuilder $scope) use ($industryIds, $industryNames): void {
            $hasCondition = false;

            $hasCondition = $this->orWhereColumnIn($scope, 'circles', 'industry_id', $industryIds) || $hasCondition;

            if (Schema::hasColumn('circles', 'industry_tags')) {
                foreach ([...$industryIds, ...$industryNames] as $industryValue) {
                    $scope->orWhereJsonContains('circles.industry_tags', $industryValue);
                    $hasCondition = true;
                }
            }

            if (Schema::hasTable('circle_category_mappings')) {
                $categoryIds = $this->idsForColumn('circle_category_mappings', 'category_id', $industryIds);

                if ($categoryIds !== []) {
                    $scope->orWhereExists(function (QueryBuilder $subQuery) use ($categoryIds): void {
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

        $memberIds = $this->memberIdsForAdmin($adminUser);

        if ($memberIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($userColumn, $memberIds);
    }

    public function applyToActivityQuery($query, ?AdminUser $adminUser, array $userColumns): void
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return;
        }

        $memberIds = $this->memberIdsForAdmin($adminUser);

        if ($memberIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($scope) use ($userColumns, $memberIds): void {
            $hasColumn = false;

            foreach ($userColumns as $userColumn) {
                if (! is_string($userColumn) || trim($userColumn) === '') {
                    continue;
                }

                $scope->orWhereIn($userColumn, $memberIds);
                $hasColumn = true;
            }

            if (! $hasColumn) {
                $scope->whereRaw('1 = 0');
            }
        });
    }

    public function userInScope(?AdminUser $adminUser, string $userId): bool
    {
        if (! $this->isIndustryDirector($adminUser)) {
            return true;
        }

        return in_array($userId, $this->memberIdsForAdmin($adminUser), true);
    }

    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $ids,
        ), fn (string $id) => $id !== '')));
    }

    private function orWhereColumnIn(QueryBuilder $query, string $table, string $column, array $ids): bool
    {
        $compatibleIds = $this->idsForColumn($table, $column, $ids);

        if ($compatibleIds === []) {
            return false;
        }

        $query->orWhereIn("{$table}.{$column}", $compatibleIds);

        return true;
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
            return strtolower((string) Schema::getColumnType($table, $column));
        }
    }

    private function schemaName(): string
    {
        $schema = (string) config('database.connections.' . config('database.default') . '.search_path', 'public');
        $schema = trim((string) explode(',', $schema)[0], " \t\n\r\0\x0B\"");

        return $schema !== '' ? $schema : 'public';
    }
}
