<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\AdminDedDistrict;
use App\Models\CircleMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AdminAccess
{
    private const CACHE_TTL = 300;

    private const SUPER_ROLE_KEYS = [
        'global_admin',
        'industry_director',
    ];

    private const CIRCLE_SCOPED_KEYS = [
        'circle_leader',
        'chair',
        'vice_chair',
        'secretary',
        'founder',
        'director',
        'member',
    ];

    private const CIRCLE_ROLE_PRIORITY = [
        'chair' => 1,
        'vice_chair' => 2,
        'secretary' => 3,
        'founder' => 4,
        'director' => 5,
        'committee_leader' => 6,
        'member' => 7,
    ];

    private const CIRCLE_ROLE_LABELS = [
        'chair' => 'Chair',
        'vice_chair' => 'Vice Chair',
        'secretary' => 'Secretary',
        'founder' => 'Founder',
        'director' => 'Director',
        'committee_leader' => 'Committee Leader',
        'member' => 'Member',
    ];

    public static function resolveAppUser(?AdminUser $admin): ?User
    {
        if (! $admin) {
            return null;
        }

        $cacheKey = 'admin-access:user:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $email = trim(strtolower((string) $admin->email));
            if ($email === '') {
                return null;
            }

            return User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        });
    }

    public static function adminRoleKeys(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:roles:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            return Role::query()
                ->join('admin_user_roles', 'admin_user_roles.role_id', '=', 'roles.id')
                ->where('admin_user_roles.user_id', $admin->id)
                ->pluck('roles.key')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function isSuper(?AdminUser $admin): bool
    {
        $roleKeys = self::adminRoleKeys($admin);

        return (bool) array_intersect(self::SUPER_ROLE_KEYS, $roleKeys);
    }

    public static function isGlobalAdmin(?AdminUser $admin): bool
    {
        if (! $admin) {
            return false;
        }

        return in_array('global_admin', self::adminRoleKeys($admin), true);
    }


    public static function isDed(?AdminUser $admin): bool
    {
        if (! $admin || self::isGlobalAdmin($admin)) {
            return false;
        }

        return in_array('ded', self::adminRoleKeys($admin), true);
    }

    public static function assignedDedStateId(?AdminUser $admin): ?string
    {
        return self::assignedDedLocation($admin)['state_id'] ?? null;
    }

    public static function assignedDedDistrictId(?AdminUser $admin): ?string
    {
        return self::assignedDedLocation($admin)['district_id'] ?? null;
    }

    public static function assignedDedDistrictName(?AdminUser $admin): ?string
    {
        return self::assignedDedLocation($admin)['district_name'] ?? null;
    }

    public static function assignedDedStateName(?AdminUser $admin): ?string
    {
        return self::assignedDedLocation($admin)['state_name'] ?? null;
    }

    public static function assignedDedLocation(?AdminUser $admin): array
    {
        if (! $admin || ! self::isDed($admin) || ! Schema::hasTable('admin_ded_districts')) {
            return [];
        }

        $cacheKey = 'admin-access:ded-location:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin): array {
            $query = AdminDedDistrict::query()
                ->from('admin_ded_districts')
                ->where('admin_ded_districts.admin_user_id', $admin->id);

            if (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id')) {
                $query->leftJoin('districts', 'districts.id', '=', 'admin_ded_districts.district_id')
                    ->addSelect('districts.name as districts_table_name');
            }

            if (Schema::hasColumn('admin_ded_districts', 'state_id')) {
                $query->addSelect('admin_ded_districts.state_id');
            } elseif (Schema::hasTable('districts') && Schema::hasColumn('districts', 'state_id')) {
                $query->addSelect('districts.state_id');
            }

            if (Schema::hasColumn('admin_ded_districts', 'district_id')) {
                $query->addSelect('admin_ded_districts.district_id');
            }

            if (Schema::hasColumn('admin_ded_districts', 'district_name')) {
                $query->addSelect('admin_ded_districts.district_name as assigned_district_name');
            }

            if (Schema::hasColumn('admin_ded_districts', 'state_name')) {
                $query->addSelect('admin_ded_districts.state_name as assigned_state_name');
            }

            if (Schema::hasTable('states') && (Schema::hasColumn('admin_ded_districts', 'state_id') || (Schema::hasTable('districts') && Schema::hasColumn('admin_ded_districts', 'district_id') && Schema::hasColumn('districts', 'state_id')))) {
                $stateJoinColumn = Schema::hasColumn('admin_ded_districts', 'state_id')
                    ? 'admin_ded_districts.state_id'
                    : 'districts.state_id';

                $query->leftJoin('states', 'states.id', '=', $stateJoinColumn)
                    ->addSelect('states.name as states_table_name');
            }

            $assignment = $query->first();

            if (! $assignment) {
                return [];
            }

            return [
                'state_id' => $assignment->state_id ?? null,
                'state_name' => ($assignment->states_table_name ?? null) ?: ($assignment->assigned_state_name ?? null),
                'district_id' => $assignment->district_id ?? null,
                'district_name' => ($assignment->districts_table_name ?? null) ?: ($assignment->assigned_district_name ?? null),
            ];
        });
    }

    public static function isCircleScoped(?AdminUser $admin): bool
    {
        if (! $admin || self::isSuper($admin)) {
            return false;
        }

        $roleKeys = self::adminRoleKeys($admin);
        $hasCircleRoleKey = (bool) array_intersect(self::CIRCLE_SCOPED_KEYS, $roleKeys);

        if ($hasCircleRoleKey) {
            return true;
        }

        $user = self::resolveAppUser($admin);

        if (! $user) {
            return false;
        }

        $allowedRoles = array_keys(self::CIRCLE_ROLE_PRIORITY);
        $isPgSql = DB::connection()->getDriverName() === 'pgsql';
        $roleCol = $isPgSql ? DB::raw('circle_members.role::text') : 'circle_members.role';

        return CircleMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->whereIn($roleCol, $allowedRoles)
            ->exists();
    }

    public static function allowedCircleIds(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:circles:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $user = self::resolveAppUser($admin);
            if (! $user) {
                return [];
            }

            return CircleMember::query()
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->pluck('circle_id')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function allowedUserIds(?AdminUser $admin): array
    {
        if (! $admin) {
            return [];
        }

        $cacheKey = 'admin-access:allowed-users:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $allowedCircleIds = self::allowedCircleIds($admin);
            if ($allowedCircleIds === []) {
                return [];
            }

            return CircleMember::query()
                ->whereIn('circle_id', $allowedCircleIds)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function primaryCircleRoleKey(?AdminUser $admin): ?string
    {
        if (! $admin) {
            return null;
        }

        $cacheKey = 'admin-access:primary-role:' . $admin->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($admin) {
            $user = self::resolveAppUser($admin);
            if (! $user) {
                return null;
            }

            $allowedCircleIds = self::allowedCircleIds($admin);
            if ($allowedCircleIds === []) {
                return null;
            }

            $roles = array_keys(self::CIRCLE_ROLE_PRIORITY);
            $orderCases = collect(self::CIRCLE_ROLE_PRIORITY)
                ->map(fn ($priority, $role) => "when '{$role}' then {$priority}")
                ->implode(' ');

            $isPgSql = DB::connection()->getDriverName() === 'pgsql';
            $roleCol = $isPgSql ? DB::raw('circle_members.role::text') : 'circle_members.role';
            $orderRoleCol = $isPgSql ? 'circle_members.role::text' : 'circle_members.role';
            $valueRoleCol = $isPgSql ? DB::raw('circle_members.role::text') : 'circle_members.role';

            return CircleMember::query()
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->whereIn('circle_id', $allowedCircleIds)
                ->whereIn($roleCol, $roles)
                ->orderByRaw("case {$orderRoleCol} {$orderCases} else 999 end")
                ->limit(1)
                ->value($valueRoleCol);
        });
    }

    public static function primaryCircleRoleLabel(?AdminUser $admin): string
    {
        $roleKey = self::primaryCircleRoleKey($admin);

        if (! $roleKey) {
            return 'Circle Leader';
        }

        return self::CIRCLE_ROLE_LABELS[$roleKey] ?? 'Circle Leader';
    }

    public static function canEditUsers(?AdminUser $admin): bool
    {
        if (! $admin) {
            return false;
        }

        return in_array('global_admin', self::adminRoleKeys($admin), true);
    }
}
