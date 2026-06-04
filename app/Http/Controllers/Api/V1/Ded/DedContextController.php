<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\Ded\DedApiService;
use App\Support\AdminAccess;
use Illuminate\Http\Request;

class DedContextController extends Controller
{
    public function __construct(private readonly DedApiService $ded) {}

    public function me(Request $request)
    {
        $admin = $this->ded->admin($request);
        $actor = $request->attributes->get('ded_actor');
        $location = $this->ded->location($request);

        return $this->ded->success([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
            'user' => $actor ? $this->ded->userSummary($actor) : null,
            'role' => 'ded',
            'roles' => AdminAccess::adminRoleKeys($admin),
            'assigned_state' => [
                'id' => $location['state_id'] ?? null,
                'name' => $location['state_name'] ?? null,
            ],
            'assigned_district' => [
                'id' => $location['district_id'] ?? null,
                'name' => $location['district_name'] ?? null,
            ],
            'permissions' => [
                'district_scope' => true,
                'approve_circle_join_requests' => true,
                'view_pending_requests' => true,
                'view_reports' => true,
            ],
            'available_modules' => [
                'dashboard', 'circles', 'peers', 'activities', 'coins', 'pending_requests', 'reports',
            ],
        ], 'DED context loaded.');
    }
}
