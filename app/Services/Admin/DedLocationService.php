<?php

namespace App\Services\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DedLocationService
{
    private bool $locationsSynced = false;

    public function __construct(private readonly DistrictSyncService $districtSyncService)
    {
    }

    public function getAvailableStates(): Collection
    {
        if (! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return collect();
        }

        $stateKeysWithData = $this->stateKeysWithData();
        if ($stateKeysWithData === []) {
            return collect();
        }

        $unique = collect();

        DB::table('states')
            ->when(Schema::hasColumn('states', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->each(function (object $state) use ($unique, $stateKeysWithData): void {
                $name = $this->districtSyncService->normalizeStateName($state->name ?? null);
                $key = $this->districtSyncService->stateKey($name);

                if (! $name || $key === '' || ! in_array($key, $stateKeysWithData, true) || $unique->has($key)) {
                    return;
                }

                $unique->put($key, (object) [
                    'id' => (string) $state->id,
                    'name' => $name,
                ]);
            });

        return $unique
            ->values()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function getAvailableDistrictsByState(?string $stateId): Collection
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'name')) {
            return collect();
        }

        $query = DB::table('districts')
            ->when(Schema::hasColumn('districts', 'status'), fn (Builder $builder) => $builder->where('districts.status', 'active'));

        if ($stateId && Schema::hasColumn('districts', 'state_id')) {
            $stateIds = $this->equivalentStateIds($stateId);
            $query->whereIn('districts.state_id', $stateIds !== [] ? $stateIds : [$stateId]);
        }

        return $this->districtSyncService->uniqueDistrictRows(
            $query->orderBy('districts.name')->get(['districts.id', 'districts.name'])
        );
    }


    public function districtBelongsToState(string $districtId, string $stateId): bool
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'state_id')) {
            return false;
        }

        $stateIds = $this->equivalentStateIds($stateId);

        return DB::table('districts')
            ->where('id', $districtId)
            ->whereIn('state_id', $stateIds !== [] ? $stateIds : [$stateId])
            ->when(Schema::hasColumn('districts', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->exists();
    }

    public function canonicalStateIdForDistrict(string $districtId, ?string $fallbackStateId = null): ?string
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'state_id')) {
            return $fallbackStateId;
        }

        return DB::table('districts')->where('id', $districtId)->value('state_id') ?: $fallbackStateId;
    }

    public function normalizeDistrictName(?string $value): ?string
    {
        return $this->districtSyncService->normalizeDistrictName($value);
    }

    public function getAssignedDedDistrict(string $adminUserId): ?object
    {
        if (! Schema::hasTable('admin_ded_districts')) {
            return null;
        }

        $query = DB::table('admin_ded_districts')
            ->where('admin_ded_districts.admin_user_id', $adminUserId);

        if (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id')) {
            $query->leftJoin('districts', 'districts.id', '=', 'admin_ded_districts.district_id');
        }

        if (Schema::hasTable('states') && Schema::hasColumn('admin_ded_districts', 'state_id')) {
            $query->leftJoin('states', 'states.id', '=', 'admin_ded_districts.state_id');
        }

        $selects = ['admin_ded_districts.admin_user_id'];
        foreach (['state_id', 'district_id', 'state_name', 'district_name'] as $column) {
            if (Schema::hasColumn('admin_ded_districts', $column)) {
                $selects[] = 'admin_ded_districts.' . $column;
            }
        }

        if (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id')) {
            $selects[] = 'districts.name as districts_table_name';
        }

        if (Schema::hasTable('states') && Schema::hasColumn('admin_ded_districts', 'state_id')) {
            $selects[] = 'states.name as states_table_name';
        }

        $assignment = $query->select($selects)->first();

        if (! $assignment) {
            return null;
        }

        $districtName = $this->normalizeDistrictName($assignment->districts_table_name ?? null)
            ?: $this->normalizeDistrictName($assignment->district_name ?? null);
        $stateName = $this->districtSyncService->normalizeStateName($assignment->states_table_name ?? null)
            ?: $this->districtSyncService->normalizeStateName($assignment->state_name ?? null);

        return (object) [
            'state_id' => $assignment->state_id ?? null,
            'state_name' => $stateName,
            'district_id' => $assignment->district_id ?? null,
            'district_name' => $districtName,
        ];
    }

    public function applyDedDistrictScope($query, ?string $districtName, string $userColumn = 'users.city'): void
    {
        $districtName = $this->normalizeDistrictName($districtName);

        if (! $districtName) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereRaw('LOWER(NULLIF(TRIM(' . $userColumn . "), '')) = ?", [Str::lower($districtName)]);
    }

    public function resolveDistrictId(?string $districtName, ?string $stateId = null): ?string
    {
        $districtName = $this->normalizeDistrictName($districtName);

        if (! $districtName || ! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'name')) {
            return null;
        }

        $query = DB::table('districts')
            ->whereRaw("LOWER(NULLIF(TRIM(name), '')) = ?", [Str::lower($districtName)]);

        if ($stateId && Schema::hasColumn('districts', 'state_id')) {
            $query->where('state_id', $stateId);
        }

        if (Schema::hasColumn('districts', 'status')) {
            $query->where('status', 'active');
        }

        return $query->value('id') ?: null;
    }

    public function resolveStateName(?string $stateId): ?string
    {
        if (! $stateId || ! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return null;
        }

        $state = DB::table('states')
            ->where('id', $stateId)
            ->value('name');

        return $state ? $this->displayName($state) : null;
    }

    public function syncKnownLocations(): void
    {
        if ($this->locationsSynced) {
            return;
        }

        $this->districtSyncService->syncKnownLocations();
        $this->locationsSynced = true;
    }


    private function stateKeysWithData(): array
    {
        $actualDataKeys = collect();

        $this->appendStateKeysFromTableColumn($actualDataKeys, 'users', 'state');
        $this->appendStateKeysFromTableColumn($actualDataKeys, 'users', 'business_state');
        $this->appendStateKeysFromTableColumn($actualDataKeys, 'circles', 'state');
        $this->appendStateKeysFromCityRelation($actualDataKeys, 'users');
        $this->appendStateKeysFromCityRelation($actualDataKeys, 'circles');
        $this->appendStateKeysFromDedAssignments($actualDataKeys);

        $actualDataKeys = $actualDataKeys->filter()->unique()->values();
        if ($actualDataKeys->isNotEmpty()) {
            return $actualDataKeys->all();
        }

        return $this->stateKeysFromCanonicalDistricts();
    }

    private function appendStateKeysFromTableColumn(Collection $keys, string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($column)
            ->whereRaw("NULLIF(TRIM({$column}), '') IS NOT NULL")
            ->distinct()
            ->pluck($column)
            ->each(function ($stateName) use ($keys): void {
                $key = $this->districtSyncService->stateKey($stateName);
                if ($key !== '') {
                    $keys->push($key);
                }
            });
    }

    private function appendStateKeysFromCityRelation(Collection $keys, string $ownerTable): void
    {
        if (! Schema::hasTable($ownerTable) || ! Schema::hasColumn($ownerTable, 'city_id') || ! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'state')) {
            return;
        }

        DB::table($ownerTable)
            ->join('cities', 'cities.id', '=', $ownerTable . '.city_id')
            ->whereNotNull('cities.state')
            ->whereRaw("NULLIF(TRIM(cities.state), '') IS NOT NULL")
            ->distinct()
            ->pluck('cities.state')
            ->each(function ($stateName) use ($keys): void {
                $key = $this->districtSyncService->stateKey($stateName);
                if ($key !== '') {
                    $keys->push($key);
                }
            });
    }

    private function appendStateKeysFromDedAssignments(Collection $keys): void
    {
        if (! Schema::hasTable('admin_ded_districts')) {
            return;
        }

        if (Schema::hasColumn('admin_ded_districts', 'state_name')) {
            DB::table('admin_ded_districts')
                ->whereNotNull('state_name')
                ->whereRaw("NULLIF(TRIM(state_name), '') IS NOT NULL")
                ->distinct()
                ->pluck('state_name')
                ->each(function ($stateName) use ($keys): void {
                    $key = $this->districtSyncService->stateKey($stateName);
                    if ($key !== '') {
                        $keys->push($key);
                    }
                });
        }

        if (! Schema::hasColumn('admin_ded_districts', 'state_id') || ! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return;
        }

        DB::table('admin_ded_districts')
            ->join('states', 'states.id', '=', 'admin_ded_districts.state_id')
            ->whereNotNull('admin_ded_districts.state_id')
            ->distinct()
            ->pluck('states.name')
            ->each(function ($stateName) use ($keys): void {
                $key = $this->districtSyncService->stateKey($stateName);
                if ($key !== '') {
                    $keys->push($key);
                }
            });
    }

    private function stateKeysFromCanonicalDistricts(): array
    {
        if (! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'state_id') || ! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return [];
        }

        return DB::table('districts')
            ->join('states', 'states.id', '=', 'districts.state_id')
            ->when(Schema::hasColumn('districts', 'status'), fn (Builder $query) => $query->where('districts.status', 'active'))
            ->whereNotNull('districts.state_id')
            ->distinct()
            ->pluck('states.name')
            ->map(fn ($stateName): string => $this->districtSyncService->stateKey($stateName))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function equivalentStateIds(string $stateId): array
    {
        if (! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return [$stateId];
        }

        $stateName = DB::table('states')->where('id', $stateId)->value('name');
        $stateKey = $this->districtSyncService->stateKey($stateName);

        if ($stateKey === '') {
            return [$stateId];
        }

        return DB::table('states')
            ->get(['id', 'name'])
            ->filter(fn (object $state): bool => $this->districtSyncService->stateKey($state->name ?? null) === $stateKey)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function displayName(?string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        $value = trim($value, '"');

        if ($value === '') {
            return '';
        }

        return Str::title(Str::lower($value));
    }
}
