<?php

namespace App\Services\AdminCampaigns;

use App\Models\Circle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CampaignRecipientResolverService
{
    public function query(string $audienceType, ?array $filters = [], bool $requiresEmail = false): Builder
    {
        $recipientIds = $this->recipientIdQuery($audienceType, $filters, $requiresEmail);

        return User::query()
            ->select('users.*')
            ->whereIn('users.id', $recipientIds)
            ->with(['circleMembers.circle:id,name'])
            ->orderBy('users.created_at');
    }

    public function preview(string $audienceType, ?array $filters = [], bool $requiresEmail = false, int $limit = 500): array
    {
        $users = $this->query($audienceType, $filters, $requiresEmail)->limit($limit)->get();

        return $users->map(fn (User $user): array => $this->formatUser($user))->values()->all();
    }

    public function count(string $audienceType, ?array $filters = [], bool $requiresEmail = false): int
    {
        return User::query()
            ->whereIn('users.id', $this->recipientIdQuery($audienceType, $filters, $requiresEmail))
            ->count();
    }

    private function recipientIdQuery(string $audienceType, ?array $filters = [], bool $requiresEmail = false): Builder
    {
        $filters = $filters ?: [];
        $query = User::query()->select('users.id')->distinct();

        $this->applyActiveUserScope($query);
        $this->applyAudienceFilter($query, $audienceType, $filters);

        if ($requiresEmail) {
            $query->whereNotNull('users.email')->where('users.email', '!=', '');
        }

        return $query;
    }

    public function filterOptions(): array
    {
        return [
            'cities' => $this->distinctUserColumn($this->cityColumn()),
            'companies' => $this->distinctUserColumn('company_name'),
            'circles' => Circle::query()->select('id', 'name')->orderBy('name')->get()->map(fn (Circle $circle) => [
                'id' => (string) $circle->id,
                'name' => $circle->name,
            ])->values()->all(),
            'categories' => $this->categories(),
            'membership_statuses' => $this->distinctUserColumn('membership_status'),
        ];
    }

    public function searchMembers(string $search = '', int $limit = 25): array
    {
        $search = trim($search);
        $query = User::query()->select(['id', 'display_name', 'first_name', 'last_name', 'email', 'phone', 'company_name', 'city', 'city_of_residence', 'membership_status']);
        $this->applyActiveUserScope($query);

        if ($search !== '') {
            $like = '%' . Str::lower($search) . '%';
            $query->where(function (Builder $builder) use ($like): void {
                foreach (['first_name', 'last_name', 'display_name', 'email', 'phone', 'company_name'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $builder->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$like]);
                    }
                }
            });
        }

        return $query->orderBy('display_name')->limit($limit)->get()->map(fn (User $user): array => $this->formatUser($user))->values()->all();
    }

    private function applyAudienceFilter(Builder $query, string $audienceType, array $filters): void
    {
        match ($audienceType) {
            'city' => $this->whereInFilter($query, $this->cityColumn(), $filters['cities'] ?? $filters['city'] ?? []),
            'circle' => $this->applyCircleFilter($query, $filters['circle_ids'] ?? $filters['circles'] ?? []),
            'company' => $this->applyCompanyFilter($query, $filters['companies'] ?? $filters['company_names'] ?? []),
            'category' => $this->applyBusinessCategoryFilter($query, $filters),
            'membership_status' => $this->whereInFilter($query, 'membership_status', $filters['membership_statuses'] ?? $filters['statuses'] ?? []),
            'specific_members' => $this->whereInFilter($query, 'id', $filters['user_ids'] ?? $filters['members'] ?? []),
            'custom_filter' => $this->applyCustomFilter($query, $filters),
            default => null,
        };
    }

    private function applyCustomFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['cities'])) {
            $this->whereInFilter($query, $this->cityColumn(), $filters['cities']);
        }
        if (! empty($filters['circle_ids'])) {
            $this->applyCircleFilter($query, $filters['circle_ids']);
        }
        if (! empty($filters['companies'])) {
            $this->applyCompanyFilter($query, $filters['companies']);
        }
        if (! empty($filters['business_category_ids']) || ! empty($filters['category_ids'])) {
            $this->applyBusinessCategoryFilter($query, $filters);
        }
        if (! empty($filters['membership_statuses'])) {
            $this->whereInFilter($query, 'membership_status', $filters['membership_statuses']);
        }
    }

    private function applyCircleFilter(Builder $query, mixed $circleIds): void
    {
        $circleIds = $this->cleanArray($circleIds);
        if ($circleIds === []) {
            return;
        }

        $query->join('circle_members as campaign_cm', 'campaign_cm.user_id', '=', 'users.id')
            ->whereIn('campaign_cm.circle_id', $circleIds);

        if (Schema::hasColumn('circle_members', 'status')) {
            $query->whereIn('campaign_cm.status', ['approved']);
        }
        if (Schema::hasColumn('circle_members', 'deleted_at')) {
            $query->whereNull('campaign_cm.deleted_at');
        }
    }

    private function applyCompanyFilter(Builder $query, mixed $companies): void
    {
        $companies = $this->cleanArray($companies);
        if ($companies === []) {
            return;
        }

        $query->where(function (Builder $builder) use ($companies): void {
            foreach ($companies as $company) {
                $builder->orWhereRaw('users.company_name ILIKE ?', [$company]);
            }
        });
    }

    public function businessCategoryIdsFromFilters(array $filters): array
    {
        return $this->cleanArray($filters['business_category_ids'] ?? $filters['category_ids'] ?? $filters['categories'] ?? []);
    }


    public function resolveBusinessCategoryValues(array $values): array
    {
        $values = $this->cleanArray($values);
        if ($values === []) {
            return [];
        }

        $categories = collect();
        foreach ($this->categoryOptionSources() as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['name_column'])) {
                continue;
            }

            $query = DB::table($source['table'])
                ->select(['id', DB::raw("{$source['name_column']} as name")])
                ->where(function ($builder) use ($source, $values): void {
                    $compatibleIds = $this->valuesCompatibleWithColumn($source['table'], 'id', $values);
                    if ($compatibleIds !== []) {
                        $builder->orWhereIn('id', $compatibleIds);
                    }

                    foreach ($values as $value) {
                        $builder->orWhereRaw("LOWER({$source['name_column']}) = ?", [Str::lower($value)]);
                    }
                });

            $categories = $categories->merge($query->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ]));
        }

        return $categories
            ->unique(fn (array $category): string => $category['id'])
            ->values()
            ->all();
    }

    public function businessCategoryNames(array $ids): array
    {
        $ids = $this->cleanArray($ids);
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->categoryOptionSources() as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['name_column'])) {
                continue;
            }

            $rows = DB::table($source['table'])
                ->whereIn('id', $this->valuesCompatibleWithColumn($source['table'], 'id', $ids))
                ->pluck($source['name_column'], 'id');

            foreach ($rows as $id => $name) {
                $names[(string) $id] = (string) $name;
            }
        }

        return collect($ids)->map(fn (string $id): string => $names[$id] ?? $id)->values()->all();
    }

    public function describeFilters(?array $filters): array
    {
        $filters = $filters ?: [];
        $businessCategoryIds = $this->businessCategoryIdsFromFilters($filters);

        return [
            'business_categories' => $this->businessCategoryNames($businessCategoryIds),
            'business_category_ids' => $businessCategoryIds,
        ];
    }

    private function applyBusinessCategoryFilter(Builder $query, array $filters): void
    {
        $categoryIds = $this->businessCategoryIdsFromFilters($filters);
        if ($categoryIds === []) {
            return;
        }

        $query->where(function (Builder $builder) use ($categoryIds): void {
            $applied = false;

            foreach (['business_category_id', 'main_business_category_id'] as $column) {
                if (! Schema::hasColumn('users', $column)) {
                    continue;
                }

                $values = $this->valuesCompatibleWithColumn('users', $column, $categoryIds);
                if ($values === []) {
                    continue;
                }

                $applied = true;
                $builder->orWhereIn('users.' . $column, $values);
            }

            if (Schema::hasColumn('users', 'business_category')) {
                $categoryNames = $this->businessCategoryNames($categoryIds);
                if ($categoryNames !== []) {
                    $applied = true;
                    $builder->orWhereIn('users.business_category', $categoryNames);
                }
            }

            if (! $applied) {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    private function whereInFilter(Builder $query, string $column, mixed $values): void
    {
        if (! Schema::hasColumn('users', $column)) {
            return;
        }
        $values = $this->cleanArray($values);
        if ($values !== []) {
            $query->whereIn('users.' . $column, $values);
        }
    }

    private function applyActiveUserScope(Builder $query): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('users.deleted_at');
        }
        if (Schema::hasColumn('users', 'gdpr_deleted_at')) {
            $query->whereNull('users.gdpr_deleted_at');
        }
    }

    private function cleanArray(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])->map(fn ($item) => trim((string) $item))->filter()->unique()->values()->all();
    }

    private function cityColumn(): string
    {
        return Schema::hasColumn('users', 'city') ? 'city' : 'city_of_residence';
    }

    private function distinctUserColumn(string $column): array
    {
        if (! Schema::hasColumn('users', $column)) {
            return [];
        }

        $query = User::query()->whereNotNull($column);

        if ($column !== 'membership_status') {
            $query->where($column, '!=', '');
        }

        return $query->distinct()->orderBy($column)->pluck($column)->values()->all();
    }

    private function categories(): array
    {
        $categories = collect();

        foreach ($this->categoryOptionSources() as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['name_column'])) {
                continue;
            }

            $query = DB::table($source['table'])->select(['id', DB::raw("{$source['name_column']} as name")]);
            if (Schema::hasColumn($source['table'], 'is_active')) {
                $query->where('is_active', true);
            }

            $categories = $categories->merge($query->orderBy($source['name_column'])->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ]));
        }

        return $categories
            ->unique(fn (array $category): string => $category['id'] . '|' . $category['name'])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function categoryOptionSources(): array
    {
        return collect([
            ['table' => 'circle_categories', 'name_column' => 'name'],
            ['table' => 'level4_categories', 'name_column' => 'name'],
            ['table' => 'circle_category_level4', 'name_column' => 'name'],
            ['table' => 'business_categories', 'name_column' => 'name'],
            ['table' => 'categories', 'name_column' => 'category_name'],
        ])->filter(fn (array $source): bool => Schema::hasTable($source['table']))->values()->all();
    }

    private function valuesCompatibleWithColumn(string $table, string $column, array $values): array
    {
        $type = $this->columnDataType($table, $column);
        if ($type === null) {
            return $values;
        }

        if (in_array($type, ['uuid'], true)) {
            return collect($values)->filter(fn (string $value): bool => Str::isUuid($value))->values()->all();
        }

        if (str_contains($type, 'int') || in_array($type, ['bigint', 'integer', 'smallint'], true)) {
            return collect($values)->filter(fn (string $value): bool => preg_match('/^-?\d+$/', $value) === 1)->values()->all();
        }

        return $values;
    }

    private function columnDataType(string $table, string $column): ?string
    {
        try {
            return DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'display_name' => $user->adminDisplayName(),
            'email' => $user->email,
            'phone' => $user->phone,
            'city' => $user->city ?: ($user->city_of_residence ?? null),
            'company_name' => $user->company_name,
            'membership_status' => $user->membership_status,
            'circle_name' => method_exists($user, 'adminCircleLabel') ? $user->adminCircleLabel() : null,
        ];
    }
}
