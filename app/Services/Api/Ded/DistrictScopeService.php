<?php

namespace App\Services\Api\Ded;

use App\Models\AdminUser;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class DistrictScopeService
{
    /**
     * Get DED user's assigned district location.
     */
    public function getAssignedDistrict(AdminUser $admin): ?object
    {
        $location = AdminAccess::assignedDedLocation($admin);
        $districtName = $location['district_name'] ?? null;
        $stateName = $location['state_name'] ?? null;
        $districtId = $location['district_id'] ?? null;
        $stateId = $location['state_id'] ?? null;

        if (! $districtName) {
            return null;
        }

        return (object) [
            'id' => $districtId,
            'name' => $districtName,
            'state_id' => $stateId,
            'state_name' => $stateName,
        ];
    }

    /**
     * Apply DED district scoping to a users query.
     */
    public function applyUsersScope(EloquentBuilder $query, AdminUser $admin): void
    {
        AdminCircleScope::applyToUsersQuery($query, $admin);
    }

    /**
     * Apply DED district scoping to a circles query.
     */
    public function applyCirclesScope(EloquentBuilder $query, AdminUser $admin): void
    {
        AdminCircleScope::applyToCirclesQuery($query, $admin);
    }

    /**
     * Apply DED district scoping to an activity/event query.
     */
    public function applyActivityScope($query, AdminUser $admin, string $primaryColumn, ?string $peerColumn = null): void
    {
        AdminCircleScope::applyToActivityQuery($query, $admin, $primaryColumn, $peerColumn);
    }
}
