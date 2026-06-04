<?php

namespace App\Services\Admin;

use App\Models\Circle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DistrictSyncService
{
    private const INVALID_EXACT_VALUES = [
        '', '-', '--', 'n/a', 'na', 'none', 'null', 'no city', 'nocity', 'unknown',
        'not available', 'notavailable', 'not applicable', 'notapplicable', 'all india', 'allindia', 'pan india', 'panindia', 'india',
    ];

    public function syncKnownLocations(): void
    {
        $this->safely(function (): void {
            $this->syncFromCitiesTable();
            $this->syncFromUsersTable();
            $this->syncFromCirclesTable();
        });
    }

    public function syncFromUser(User $user): void
    {
        $this->safely(function () use ($user): void {
            $location = null;

            if ($user->getAttribute('city_id')) {
                $location = $this->locationFromCityId((string) $user->getAttribute('city_id'));
            }

            if (! $location) {
                $location = $this->locationFromCityName(
                    $this->firstFilled($user->getAttribute('city'), $user->getAttribute('business_city')),
                    $this->firstFilled($user->getAttribute('state'), $user->getAttribute('business_state')),
                );
            }

            if (! $location && Schema::hasColumn('users', 'district')) {
                $location = $this->locationFromDistrictAndState(
                    $user->getAttribute('district'),
                    $this->firstFilled($user->getAttribute('state'), $user->getAttribute('business_state')),
                );
            }

            $this->upsertLocation($location);
        });
    }

    public function syncFromCircle(Circle $circle): void
    {
        $this->safely(function () use ($circle): void {
            $location = null;

            if ($circle->getAttribute('city_id')) {
                $location = $this->locationFromCityId((string) $circle->getAttribute('city_id'));
            }

            if (! $location) {
                $city = is_string($circle->getAttribute('city')) ? $circle->getAttribute('city') : $circle->city_display;
                $location = $this->locationFromCityName($city, $this->attributeIfColumnExists($circle, 'state'));
            }

            if (! $location && Schema::hasColumn('circles', 'district')) {
                $location = $this->locationFromDistrictAndState(
                    $circle->getAttribute('district'),
                    $this->attributeIfColumnExists($circle, 'state'),
                );
            }

            $this->upsertLocation($location);
        });
    }

    public function normalizeStateName(?string $value): ?string
    {
        return $this->normalizeLocationName($value, false);
    }

    public function normalizeDistrictName(?string $value): ?string
    {
        return $this->normalizeLocationName($value, true);
    }

    public function districtKey(?string $value): string
    {
        return $this->locationKey($this->normalizeDistrictName($value));
    }

    public function stateKey(?string $value): string
    {
        return $this->locationKey($this->normalizeStateName($value));
    }

    public function upsertDistrict(?string $stateName, ?string $districtName): ?string
    {
        if (! Schema::hasTable('states') || ! Schema::hasTable('districts')) {
            return null;
        }

        $stateName = $this->normalizeStateName($stateName);
        $districtName = $this->normalizeDistrictName($districtName);

        if (! $stateName || ! $districtName) {
            return null;
        }

        return DB::transaction(function () use ($stateName, $districtName): ?string {
            $stateId = $this->resolveOrCreateStateId($stateName);
            if (! $stateId) {
                return null;
            }

            $existingDistrict = $this->findDistrictByNormalizedName($stateId, $districtName);
            $now = now();

            if ($existingDistrict) {
                DB::table('districts')
                    ->where('id', $existingDistrict->id)
                    ->update(array_filter([
                        'name' => $districtName,
                        'status' => Schema::hasColumn('districts', 'status') ? 'active' : null,
                        'updated_at' => $now,
                    ], fn ($value) => $value !== null));

                return (string) $existingDistrict->id;
            }

            $districtId = (string) Str::uuid();
            DB::table('districts')->insert([
                'id' => $districtId,
                'state_id' => $stateId,
                'name' => $districtName,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $districtId;
        });
    }

    public function uniqueDistrictRows(Collection $rows): Collection
    {
        $unique = collect();

        foreach ($rows as $row) {
            $name = $this->normalizeDistrictName($row->name ?? null);
            $key = $this->districtKey($name);

            if (! $name || $key === '' || $unique->has($key)) {
                continue;
            }

            $unique->put($key, (object) [
                'id' => (string) $row->id,
                'name' => $name,
                'district_name' => $name,
                'district_id' => (string) $row->id,
            ]);
        }

        return $unique
            ->values()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function syncFromCitiesTable(): void
    {
        if (! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'state')) {
            return;
        }

        $columns = ['name', 'state'];
        if (Schema::hasColumn('cities', 'district')) {
            $columns[] = 'district';
        }

        DB::table('cities')
            ->select($columns)
            ->whereNotNull('state')
            ->orderBy('name')
            ->chunk(500, function (Collection $cities): void {
                foreach ($cities as $city) {
                    $district = $this->normalizeDistrictName($city->district ?? null)
                        ?: $this->normalizeDistrictName($city->name ?? null);
                    $this->upsertDistrict($city->state ?? null, $district);
                }
            });
    }

    private function syncFromUsersTable(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = array_values(array_filter([
            'id',
            Schema::hasColumn('users', 'city_id') ? 'city_id' : null,
            Schema::hasColumn('users', 'city') ? 'city' : null,
            Schema::hasColumn('users', 'state') ? 'state' : null,
            Schema::hasColumn('users', 'district') ? 'district' : null,
            Schema::hasColumn('users', 'business_city') ? 'business_city' : null,
            Schema::hasColumn('users', 'business_state') ? 'business_state' : null,
        ]));

        DB::table('users')
            ->select($columns)
            ->orderBy('id')
            ->chunk(500, function (Collection $users): void {
                foreach ($users as $user) {
                    $location = null;

                    if (! empty($user->city_id)) {
                        $location = $this->locationFromCityId((string) $user->city_id);
                    }

                    if (! $location) {
                        $location = $this->locationFromCityName(
                            $this->firstFilled($user->city ?? null, $user->business_city ?? null),
                            $this->firstFilled($user->state ?? null, $user->business_state ?? null),
                        );
                    }

                    if (! $location) {
                        $location = $this->locationFromDistrictAndState(
                            $user->district ?? null,
                            $this->firstFilled($user->state ?? null, $user->business_state ?? null),
                        );
                    }

                    $this->upsertLocation($location);
                }
            });
    }

    private function syncFromCirclesTable(): void
    {
        if (! Schema::hasTable('circles')) {
            return;
        }

        $columns = array_values(array_filter([
            'id',
            Schema::hasColumn('circles', 'city_id') ? 'city_id' : null,
            Schema::hasColumn('circles', 'city') ? 'city' : null,
            Schema::hasColumn('circles', 'state') ? 'state' : null,
            Schema::hasColumn('circles', 'district') ? 'district' : null,
        ]));

        DB::table('circles')
            ->select($columns)
            ->orderBy('id')
            ->chunk(500, function (Collection $circles): void {
                foreach ($circles as $circle) {
                    $location = null;

                    if (! empty($circle->city_id)) {
                        $location = $this->locationFromCityId((string) $circle->city_id);
                    }

                    if (! $location) {
                        $location = $this->locationFromCityName($circle->city ?? null, $circle->state ?? null);
                    }

                    if (! $location) {
                        $location = $this->locationFromDistrictAndState($circle->district ?? null, $circle->state ?? null);
                    }

                    $this->upsertLocation($location);
                }
            });
    }

    private function locationFromCityId(string $cityId): ?array
    {
        if (! Schema::hasTable('cities')) {
            return null;
        }

        $columns = ['name', 'state'];
        if (Schema::hasColumn('cities', 'district')) {
            $columns[] = 'district';
        }

        $city = DB::table('cities')->where('id', $cityId)->first($columns);

        if (! $city) {
            return null;
        }

        $state = $this->normalizeStateName($city->state ?? null);
        $district = $this->normalizeDistrictName($city->district ?? null) ?: $this->normalizeDistrictName($city->name ?? null);

        return ($state && $district) ? compact('state', 'district') : null;
    }

    private function locationFromCityName(?string $cityName, ?string $stateName): ?array
    {
        $cityName = $this->normalizeDistrictName($cityName);
        $stateName = $this->normalizeStateName($stateName);

        if (! $cityName) {
            return null;
        }

        if (Schema::hasTable('cities')) {
            $query = DB::table('cities')
                ->whereRaw("LOWER(NULLIF(TRIM(name), '')) = ?", [Str::lower($cityName)]);

            if ($stateName && Schema::hasColumn('cities', 'state')) {
                $query->orderByRaw("CASE WHEN LOWER(NULLIF(TRIM(state), '')) = ? THEN 0 ELSE 1 END", [Str::lower($stateName)]);
            }

            $columns = ['name', 'state'];
            if (Schema::hasColumn('cities', 'district')) {
                $columns[] = 'district';
            }

            $city = $query->first($columns);
            if ($city) {
                $state = $this->normalizeStateName($city->state ?? null) ?: $stateName;
                $district = $this->normalizeDistrictName($city->district ?? null) ?: $this->normalizeDistrictName($city->name ?? null);

                return ($state && $district) ? compact('state', 'district') : null;
            }
        }

        return ($stateName && $cityName) ? ['state' => $stateName, 'district' => $cityName] : null;
    }

    private function locationFromDistrictAndState(?string $districtName, ?string $stateName): ?array
    {
        $state = $this->normalizeStateName($stateName);
        $district = $this->normalizeDistrictName($districtName);

        return ($state && $district) ? compact('state', 'district') : null;
    }

    private function resolveOrCreateStateId(string $stateName): ?string
    {
        $existing = DB::table('states')->get(['id', 'name'])->first(
            fn (object $state): bool => $this->stateKey($state->name ?? null) === $this->stateKey($stateName)
        );

        $now = now();

        if ($existing) {
            DB::table('states')
                ->where('id', $existing->id)
                ->update(array_filter([
                    'name' => $stateName,
                    'status' => Schema::hasColumn('states', 'status') ? 'active' : null,
                    'updated_at' => $now,
                ], fn ($value) => $value !== null));

            return (string) $existing->id;
        }

        $stateId = (string) Str::uuid();
        DB::table('states')->insert([
            'id' => $stateId,
            'name' => $stateName,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $stateId;
    }

    private function findDistrictByNormalizedName(string $stateId, string $districtName): ?object
    {
        return DB::table('districts')
            ->where('state_id', $stateId)
            ->get(['id', 'name'])
            ->first(fn (object $district): bool => $this->districtKey($district->name ?? null) === $this->districtKey($districtName));
    }

    private function upsertLocation(?array $location): void
    {
        if (! $location) {
            return;
        }

        $this->upsertDistrict($location['state'] ?? null, $location['district'] ?? null);
    }

    private function normalizeLocationName(?string $value, bool $isDistrict): ?string
    {
        $value = str_replace(["\xc2\xa0", '\u{00A0}'], ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        $value = trim($value, '"\'`');

        if ($value === '') {
            return null;
        }

        if ($isDistrict) {
            $value = preg_split('/[,;|\/]+/u', $value, 2)[0] ?? $value;
            $value = preg_replace('/\s*\([^)]*\)\s*$/u', '', $value) ?: $value;
            $value = preg_replace('/^(dist\.?|district)\s+/iu', '', $value) ?: $value;
            $value = preg_replace('/\s+(district|city)$/iu', '', $value) ?: $value;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value));
        if ($value === '') {
            return null;
        }

        $name = Str::title(Str::lower($value));
        $key = $this->locationKey($name);

        if ($key === '' || in_array($key, self::INVALID_EXACT_VALUES, true)) {
            return null;
        }

        if (mb_strlen($name) > 150 || str_contains($name, "\n")) {
            return null;
        }

        if ($isDistrict && preg_match('/\d|@|https?:\/\/|\b(road|street|tower|floor|hotel|resort|restaurant|building|complex|mall|near|opposite|private|limited|company|office)\b/iu', $name)) {
            return null;
        }

        return $name;
    }

    private function locationKey(?string $value): string
    {
        $value = Str::lower((string) $value);
        $value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value) ?: $value;
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?: $value;
        $value = preg_replace('/\b(dist|district|city)\b/u', ' ', $value) ?: $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';

        return str_replace(' ', '', $value);
    }

    private function attributeIfColumnExists(Circle $circle, string $column): ?string
    {
        return Schema::hasColumn('circles', $column) && is_string($circle->getAttribute($column))
            ? $circle->getAttribute($column)
            : null;
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('admin.ded_district_sync_failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
