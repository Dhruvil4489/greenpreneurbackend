<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\CircleMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminCircleScope
{
    private const ROLE_PRIORITY = [
        'circle_leader' => 0,
        'chair' => 1,
        'vice_chair' => 2,
        'secretary' => 3,
        'founder' => 4,
        'director' => 5,
        'committee_leader' => 6,
        'member' => 7,
    ];

    public static function resolveCircleId(?AdminUser $admin): ?string
    {
        if (! $admin || ! AdminAccess::isCircleScoped($admin)) {
            return null;
        }

        $user = AdminAccess::resolveAppUser($admin);
        if (! $user) {
            return null;
        }

        $roles = array_keys(self::ROLE_PRIORITY);
        $orderCases = collect(self::ROLE_PRIORITY)
            ->map(fn ($priority, $role) => "when '{$role}' then {$priority}")
            ->implode(' ');

        $query = CircleMember::query()
            ->select('circle_members.circle_id')
            ->where('circle_members.user_id', $user->id)
            ->where('circle_members.status', 'approved')
            ->whereNull('circle_members.deleted_at')
            ->whereIn(DB::raw('circle_members.role::text'), $roles);

        if (Schema::hasColumn('circles', 'status')) {
            $query->leftJoin('circles', 'circles.id', '=', 'circle_members.circle_id')
                ->orderByRaw("case when circles.status = 'active' then 0 else 1 end");
        }

        $query->orderByRaw("case circle_members.role::text {$orderCases} else 999 end")
            ->orderBy('circle_members.created_at');

        return $query->value('circle_members.circle_id');
    }

    public static function circleUserIdsSubquery(string $circleId): Builder
    {
        return CircleMember::query()
            ->select('user_id')
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at');
    }

    public static function applyToActivityQuery($query, ?AdminUser $admin, string $primaryColumn, ?string $peerColumn): void
    {
        if (AdminAccess::isDed($admin)) {
            $query->where(function ($districtQuery) use ($admin, $primaryColumn, $peerColumn) {
                self::applyDedDistrictScope($districtQuery, $admin, $primaryColumn);

                if ($peerColumn) {
                    $districtQuery->orWhere(function ($peerDistrictQuery) use ($admin, $peerColumn) {
                        self::applyDedDistrictScope($peerDistrictQuery, $admin, $peerColumn);
                    });
                }
            });
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $circleUserIds = self::circleUserIdsSubquery($circleId);

        $query->whereIn($primaryColumn, $circleUserIds);
    }

    public static function applyToUsersQuery($query, ?AdminUser $admin): void
    {
        if (AdminAccess::isDed($admin)) {
            self::applyDedDistrictScope($query, $admin);
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($circleId) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm')
                ->whereColumn('cm.user_id', 'users.id')
                ->where('cm.status', 'approved')
                ->whereNull('cm.deleted_at')
                ->where('cm.circle_id', $circleId);
        });
    }

    public static function applyDedDistrictScope($query, ?AdminUser $admin, ?string $userColumn = null): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        $location = AdminAccess::assignedDedLocation($admin);
        $districtName = $location['district_name'] ?? null;
        $stateName = $location['state_name'] ?? null;

        if (! $districtName) {
            $query->whereRaw('1=0');
            return;
        }

        if ($userColumn) {
            $query->whereExists(function ($subQuery) use ($userColumn, $districtName, $stateName) {
                $subQuery->selectRaw(1)
                    ->from('users as ded_scope_users')
                    ->leftJoin('cities as ded_scope_cities', 'ded_scope_cities.id', '=', 'ded_scope_users.city_id')
                    ->whereColumn('ded_scope_users.id', $userColumn);

                self::applyUserLocationPredicate($subQuery, 'ded_scope_users', 'ded_scope_cities', $districtName, $stateName);
            });

            return;
        }

        $query->where(function ($scopeQuery) use ($districtName, $stateName) {
            $scopeQuery->where(function ($directUserQuery) use ($districtName, $stateName) {
                self::applyDirectUserCityPredicate($directUserQuery, 'users', $districtName, $stateName);
            });

            if (Schema::hasTable('cities') && Schema::hasColumn('users', 'city_id')) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($districtName, $stateName) {
                    $subQuery->selectRaw(1)
                        ->from('cities as ded_scope_cities')
                        ->whereColumn('ded_scope_cities.id', 'users.city_id');

                    self::applyCityDistrictPredicate($subQuery, 'ded_scope_cities', $districtName, $stateName);
                });
            }
        });
    }

    private static function applyUserLocationPredicate($query, string $userAlias, string $cityAlias, string $districtName, ?string $stateName): void
    {
        $query->where(function ($locationQuery) use ($userAlias, $cityAlias, $districtName, $stateName) {
            self::applyDirectUserCityPredicate($locationQuery, $userAlias, $districtName, $stateName);

            if (Schema::hasTable('cities')) {
                $locationQuery->orWhere(function ($cityQuery) use ($cityAlias, $districtName, $stateName) {
                    self::applyCityDistrictPredicate($cityQuery, $cityAlias, $districtName, $stateName);
                });
            }
        });
    }

    private static function applyDirectUserCityPredicate($query, string $userAlias, string $districtName, ?string $stateName): void
    {
        if (! Schema::hasColumn('users', 'city')) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereRaw("LOWER(NULLIF(TRIM({$userAlias}.city), '')) = ?", [mb_strtolower($districtName)]);
    }

    private static function applyCityDistrictPredicate($query, string $cityAlias, string $districtName, ?string $stateName): void
    {
        $query->where(function ($cityQuery) use ($cityAlias, $districtName, $stateName) {
            $hasLocationColumn = false;

            if (Schema::hasColumn('cities', 'name')) {
                $cityQuery->whereRaw("LOWER(NULLIF(TRIM({$cityAlias}.name), '')) = ?", [mb_strtolower($districtName)]);
                $hasLocationColumn = true;
            }

            if (Schema::hasColumn('cities', 'district')) {
                $method = $hasLocationColumn ? 'orWhereRaw' : 'whereRaw';
                $cityQuery->{$method}("LOWER(NULLIF(TRIM({$cityAlias}.district), '')) = ?", [mb_strtolower($districtName)]);
                $hasLocationColumn = true;
            }

            if (! $hasLocationColumn) {
                $cityQuery->whereRaw('1=0');
            }
        });

        if ($stateName && Schema::hasColumn('cities', 'state')) {
            $query->where(function ($stateQuery) use ($cityAlias, $stateName) {
                $stateQuery->whereNull("{$cityAlias}.state")
                    ->orWhereRaw("NULLIF(TRIM({$cityAlias}.state), '') IS NULL")
                    ->orWhereRaw("LOWER(NULLIF(TRIM({$cityAlias}.state), '')) = ?", [mb_strtolower($stateName)]);
            });
        }
    }


    public static function applyToCirclesQuery($query, ?AdminUser $admin, string $circleAlias = 'circles'): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        $location = AdminAccess::assignedDedLocation($admin);
        $districtName = $location['district_name'] ?? null;
        $stateName = $location['state_name'] ?? null;

        if (! $districtName) {
            $query->whereRaw('1=0');
            return;
        }

        self::applyCircleLocationPredicate($query, $circleAlias, $districtName, $stateName);
    }

    private static function applyCircleLocationPredicate($query, string $circleAlias, string $districtName, ?string $stateName): void
    {
        $query->where(function ($circleLocationQuery) use ($circleAlias, $districtName, $stateName): void {
            if (Schema::hasColumn('circles', 'city')) {
                $circleLocationQuery->whereRaw("LOWER(NULLIF(TRIM({$circleAlias}.city), '')) = ?", [mb_strtolower($districtName)]);
            } else {
                $circleLocationQuery->whereRaw('1=0');
            }

            if (Schema::hasColumn('circles', 'city_id') && Schema::hasTable('cities')) {
                $circleLocationQuery->orWhereExists(function ($citySubQuery) use ($circleAlias, $districtName, $stateName): void {
                    $citySubQuery->selectRaw(1)
                        ->from('cities as ded_scope_circle_cities')
                        ->whereColumn('ded_scope_circle_cities.id', "{$circleAlias}.city_id");

                    self::applyCityDistrictPredicate($citySubQuery, 'ded_scope_circle_cities', $districtName, $stateName);
                });
            }
        });
    }

    public static function applyToEventsQuery($query, ?AdminUser $admin, string $eventTable = 'events'): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        if (! Schema::hasColumn($eventTable, 'circle_id') || ! Schema::hasTable('circles')) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($eventTable, $admin) {
            $subQuery->selectRaw(1)
                ->from('circles as ded_scope_circles')
                ->whereColumn('ded_scope_circles.id', "{$eventTable}.circle_id");

            self::applyToCirclesQuery($subQuery, $admin, 'ded_scope_circles');
        });
    }

    public static function eventInScope(?AdminUser $admin, string $eventId): bool
    {
        if (! AdminAccess::isDed($admin)) {
            return true;
        }

        $query = \App\Models\Event::query()->whereKey($eventId);
        self::applyToEventsQuery($query, $admin);

        return $query->exists();
    }

    public static function userInScope(?AdminUser $admin, string $userId): bool
    {
        if (AdminAccess::isDed($admin)) {
            $query = User::query()->whereKey($userId);
            self::applyDedDistrictScope($query, $admin);

            return $query->exists();
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return true;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            return false;
        }

        return CircleMember::query()
            ->where('user_id', $userId)
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->exists();
    }
}
