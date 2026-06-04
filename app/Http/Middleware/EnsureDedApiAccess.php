<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Models\User;
use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDedApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        if (! $principal) {
            return $this->error('Unauthenticated.', 401);
        }

        if ($principal instanceof AdminUser) {
            $admin = $principal;
        } else {
            $admin = AdminUser::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $principal->email))])
                ->first();
        }

        if (! $admin || ! AdminAccess::isDed($admin)) {
            return $this->error('Only DED users can access this API.', 403);
        }

        $location = AdminAccess::assignedDedLocation($admin);
        if (empty($location['district_name'])) {
            return $this->error('DED district assignment is missing.', 403);
        }

        $actor = $principal instanceof User ? $principal : AdminAccess::resolveAppUser($admin);

        $request->attributes->set('ded_admin', $admin);
        $request->attributes->set('ded_actor', $actor);
        $request->attributes->set('ded_location', $location);

        return $next($request);
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [],
        ], $status);
    }
}
