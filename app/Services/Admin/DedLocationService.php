<?php

namespace App\Services\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DedLocationService
{
    private const INVALID_STATE_KEYS = [
        'national', 'allindia', 'panindia', 'india', 'global', 'worldwide',
        'eastindia', 'westindia', 'northindia', 'southindia', 'centralindia',
    ];

    private bool $locationsSynced = false;

    private ?Collection $usedLocationPairs = null;

    public function __construct(private readonly DistrictSyncService $districtSyncService)
    {
    }

    public function getAvailableStates(): Collection
    {
        if (! Schema::hasTable('states') || ! Schema::hasColumn('states', 'name')) {
            return collect();
        }

        $usedStateKeys = $this->usedLocationPairs()
            ->pluck('state_key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($usedStateKeys === []) {
            return collect();
        }

        $unique = collect();

        DB::table('states')
            ->when(Schema::hasColumn('states', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->each(function (object $state) use ($unique, $usedStateKeys): void {
                $name = $this->districtSyncService->normalizeStateName($state->name ?? null);
                $key = $this->districtSyncService->stateKey($name);

                if (! $name || ! $this->isUsableStateKey($key, $name) || ! in_array($key, $usedStateKeys, true) || $unique->has($key)) {
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
        if (! $stateId || ! Schema::hasTable('districts') || ! Schema::hasColumn('districts', 'name')) {
            return collect();
        }

        $stateName = Schema::hasTable('states') && Schema::hasColumn('states', 'name')
            ? DB::table('states')->where('id', $stateId)->value('name')
            : null;
        $stateKey = $this->districtSyncService->stateKey($stateName);

        if (! $this->isUsableStateKey($stateKey, $stateName)) {
            return collect();
        }

        $usedDistrictKeys = $this->usedLocationPairs()
            ->filter(fn (object $pair): bool => (string) $pair->state_key === $stateKey)
            ->pluck('district_key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($usedDistrictKeys === []) {
            return collect();
        }

        $query = DB::table('districts')
            ->when(Schema::hasColumn('districts', 'status'), fn (Builder $builder) => $builder->where('districts.status', 'active'));

        if (Schema::hasColumn('districts', 'state_id')) {
            $stateIds = $this->equivalentStateIds($stateId);
            $query->whereIn('districts.state_id', $stateIds !== [] ? $stateIds : [$stateId]);
        }

        $districts = $query->orderBy('districts.name')->get(['districts.id', 'districts.name'])
            ->filter(fn (object $district): bool => in_array($this->districtSyncService->districtKey($district->name ?? null), $usedDistrictKeys, true));

        return $this->districtSyncService->uniqueDistrictRows($districts);
    }


    public function districtBelongsToState(string $districtId, string $stateId): bool
    {
        return $this->getAvailableDistrictsByState($stateId)
            ->contains(fn (object $district): bool => (string) $district->id === (string) $districtId);
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


    private function usedLocationPairs(): Collection
    {
        if ($this->usedLocationPairs !== null) {
            return $this->usedLocationPairs;
        }

        $pairs = collect();

        $this->appendUsedLocationsFromCityRelation($pairs, 'users');
        $this->appendUsedLocationsFromCityRelation($pairs, 'circles');
        $this->appendUsedLocationsFromDirectColumns($pairs, 'users', 'city', 'state');
        $this->appendUsedLocationsFromDirectColumns($pairs, 'users', 'business_city', 'business_state');
        $this->appendUsedLocationsFromDirectColumns($pairs, 'users', 'district', 'state');
        $this->appendUsedLocationsFromDirectColumns($pairs, 'circles', 'city', 'state');
        $this->appendUsedLocationsFromDirectColumns($pairs, 'circles', 'district', 'state');
        $this->appendUsedLocationsFromDedAssignments($pairs);

        return $this->usedLocationPairs = $pairs
            ->filter(fn (object $pair): bool => $pair->state_key !== '' && $pair->district_key !== '')
            ->unique(fn (object $pair): string => $pair->state_key . '|' . $pair->district_key)
            ->values();
    }

    private function appendUsedLocationsFromCityRelation(Collection $pairs, string $ownerTable): void
    {
        if (! Schema::hasTable($ownerTable) || ! Schema::hasColumn($ownerTable, 'city_id') || ! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'state')) {
            return;
        }

        $districtExpression = Schema::hasColumn('cities', 'district')
            ? "COALESCE(NULLIF(TRIM(cities.district), ''), cities.name) as district_name"
            : 'cities.name as district_name';

        DB::table($ownerTable)
            ->join('cities', 'cities.id', '=', $ownerTable . '.city_id')
            ->whereNotNull($ownerTable . '.city_id')
            ->whereNotNull('cities.state')
            ->whereRaw("NULLIF(TRIM(cities.state), '') IS NOT NULL")
            ->distinct()
            ->get(['cities.state as state_name', DB::raw($districtExpression)])
            ->each(fn (object $row) => $this->pushUsedLocationPair($pairs, $row->state_name ?? null, $row->district_name ?? null));
    }

    private function appendUsedLocationsFromDirectColumns(Collection $pairs, string $table, string $districtColumn, string $stateColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $districtColumn)) {
            return;
        }

        $columns = [$districtColumn . ' as district_name'];
        $hasStateColumn = Schema::hasColumn($table, $stateColumn);
        if ($hasStateColumn) {
            $columns[] = $stateColumn . ' as state_name';
        }

        DB::table($table)
            ->whereNotNull($districtColumn)
            ->whereRaw("NULLIF(TRIM({$districtColumn}), '') IS NOT NULL")
            ->distinct()
            ->get($columns)
            ->each(function (object $row) use ($pairs, $hasStateColumn): void {
                $stateName = $hasStateColumn ? ($row->state_name ?? null) : null;
                $districtName = $row->district_name ?? null;

                if (! $this->isUsableLocationPair($stateName, $districtName)) {
                    $location = $this->canonicalLocationForDistrictName($districtName);
                    $stateName = $location->state_name ?? $stateName;
                    $districtName = $location->district_name ?? $districtName;
                }

                $this->pushUsedLocationPair($pairs, $stateName, $districtName);
            });
    }

    private function appendUsedLocationsFromDedAssignments(Collection $pairs): void
    {
        if (! Schema::hasTable('admin_ded_districts')) {
            return;
        }

        $query = DB::table('admin_ded_districts');
        $selects = [];

        if (Schema::hasColumn('admin_ded_districts', 'state_name')) {
            $selects[] = 'admin_ded_districts.state_name as assigned_state_name';
        }
        if (Schema::hasColumn('admin_ded_districts', 'district_name')) {
            $selects[] = 'admin_ded_districts.district_name as assigned_district_name';
        }

        if (Schema::hasColumn('admin_ded_districts', 'state_id') && Schema::hasTable('states') && Schema::hasColumn('states', 'name')) {
            $query->leftJoin('states', 'states.id', '=', 'admin_ded_districts.state_id');
            $selects[] = 'states.name as state_table_name';
        }

        if (Schema::hasColumn('admin_ded_districts', 'district_id') && Schema::hasTable('districts') && Schema::hasColumn('districts', 'name')) {
            $query->leftJoin('districts', 'districts.id', '=', 'admin_ded_districts.district_id');
            $selects[] = 'districts.name as district_table_name';
        }

        if ($selects === []) {
            return;
        }

        $query->select($selects)
            ->distinct()
            ->get()
            ->each(function (object $row) use ($pairs): void {
                $this->pushUsedLocationPair(
                    $pairs,
                    $row->state_table_name ?? $row->assigned_state_name ?? null,
                    $row->district_table_name ?? $row->assigned_district_name ?? null,
                );
            });
    }

    private function canonicalLocationForDistrictName(?string $districtName): ?object
    {
        $districtKey = $this->districtSyncService->districtKey($districtName);
        if ($districtKey === '' || ! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'state')) {
            return null;
        }

        $columns = ['cities.name', 'cities.state'];
        if (Schema::hasColumn('cities', 'district')) {
            $columns[] = 'cities.district';
        }

        return DB::table('cities')
            ->where(function (Builder $query) use ($districtKey): void {
                $query->whereRaw("REGEXP_REPLACE(LOWER(COALESCE(cities.name, '')), '[^a-z0-9]+', '', 'g') = ?", [$districtKey]);

                if (Schema::hasColumn('cities', 'district')) {
                    $query->orWhereRaw("REGEXP_REPLACE(LOWER(COALESCE(cities.district, '')), '[^a-z0-9]+', '', 'g') = ?", [$districtKey]);
                }
            })
            ->whereNotNull('cities.state')
            ->whereRaw("NULLIF(TRIM(cities.state), '') IS NOT NULL")
            ->get($columns)
            ->map(function (object $city): object {
                return (object) [
                    'state_name' => $city->state ?? null,
                    'district_name' => $city->district ?? $city->name ?? null,
                ];
            })
            ->first(fn (object $location): bool => $this->isUsableLocationPair($location->state_name ?? null, $location->district_name ?? null));
    }

    private function pushUsedLocationPair(Collection $pairs, ?string $stateName, ?string $districtName): void
    {
        if (! $this->isUsableLocationPair($stateName, $districtName)) {
            return;
        }

        $stateName = $this->districtSyncService->normalizeStateName($stateName);
        $districtName = $this->districtSyncService->normalizeDistrictName($districtName);

        $pairs->push((object) [
            'state_name' => $stateName,
            'district_name' => $districtName,
            'state_key' => $this->districtSyncService->stateKey($stateName),
            'district_key' => $this->districtSyncService->districtKey($districtName),
        ]);
    }

    private function isUsableLocationPair(?string $stateName, ?string $districtName): bool
    {
        $stateName = $this->districtSyncService->normalizeStateName($stateName);
        $districtName = $this->districtSyncService->normalizeDistrictName($districtName);

        if (! $stateName || ! $districtName) {
            return false;
        }

        return $this->isUsableStateKey($this->districtSyncService->stateKey($stateName), $stateName)
            && $this->districtSyncService->districtKey($districtName) !== '';
    }

    private function isUsableStateKey(string $stateKey, ?string $stateName): bool
    {
        if ($stateKey === '' || in_array($stateKey, self::INVALID_STATE_KEYS, true)) {
            return false;
        }

        return mb_strlen((string) $this->districtSyncService->normalizeStateName($stateName)) >= 3;
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
