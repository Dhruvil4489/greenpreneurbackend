<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminCircleScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if (AdminAccess::isSuper($admin)) {
            $request->attributes->set('allowed_circle_ids', []);
            $request->attributes->set('is_circle_scoped', false);
            $request->attributes->set('is_ded_scoped', false);
            return $next($request);
        }

        if (AdminAccess::isDed($admin)) {
            $districtId = AdminAccess::assignedDedDistrictId($admin);
            $request->attributes->set('allowed_circle_ids', []);
            $request->attributes->set('is_circle_scoped', false);
            $request->attributes->set('is_ded_scoped', true);
            $request->attributes->set('ded_state_id', AdminAccess::assignedDedStateId($admin));
            $request->attributes->set('ded_district_id', $districtId);
            $request->attributes->set('ded_state_name', AdminAccess::assignedDedStateName($admin));
            $request->attributes->set('ded_district_name', AdminAccess::assignedDedDistrictName($admin));

            $routeName = $request->route()?->getName() ?? '';
            $allowedPrefixes = ['admin.ded.', 'admin.users.', 'admin.activities.', 'admin.coins.', 'admin.life-impact.', 'admin.visitor-registrations.', 'admin.circle-joining-requests.', 'admin.coin-claims.', 'admin.referral-report.', 'admin.collaborations.', 'admin.circles.', 'admin.events.', 'admin.event-joining-requests.'];
            $allowedRoutes = ['admin.logout', 'admin.files.upload', 'admin.impacts.pending', 'admin.impacts.show', 'admin.impacts.approve', 'admin.impacts.reject', 'admin.impacts.export.csv'];

            if (in_array($routeName, ['admin.dashboard', 'admin.home'], true)) {
                return redirect()->route('admin.ded.dashboard');
            }

            if ($routeName !== '' && ! in_array($routeName, $allowedRoutes, true) && ! Str::startsWith($routeName, $allowedPrefixes)) {
                abort(403);
            }

            return $next($request);
        }

        if (AdminAccess::isCircleScoped($admin)) {
            $allowedCircleIds = AdminAccess::allowedCircleIds($admin);
            $request->attributes->set('allowed_circle_ids', $allowedCircleIds);
            $request->attributes->set('is_circle_scoped', true);
            $request->attributes->set('is_ded_scoped', false);
            $request->attributes->set('primary_circle_role_label', AdminAccess::primaryCircleRoleLabel($admin));

            $routeName = $request->route()?->getName() ?? '';
            $allowedPrefixes = ['admin.users.', 'admin.activities.', 'admin.coins.', 'admin.visitor-registrations.', 'admin.circle-joining-requests.'];
            $allowedRoutes = ['admin.logout', 'admin.files.upload'];

            if (in_array($routeName, ['admin.dashboard', 'admin.home'], true) || Str::startsWith($routeName, 'admin.circles.')) {
                return redirect()->route('admin.users.index');
            }

            if ($routeName !== '' && ! in_array($routeName, $allowedRoutes, true) && ! Str::startsWith($routeName, $allowedPrefixes)) {
                abort(403);
            }

            return $next($request);
        }

        $request->attributes->set('allowed_circle_ids', []);
        $request->attributes->set('is_circle_scoped', false);
        $request->attributes->set('is_ded_scoped', false);

        return $next($request);
    }
}
