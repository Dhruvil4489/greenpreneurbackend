<?php

namespace App\Http\Middleware;

use App\Models\IndustryDirectorAssignment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureIndustryDirector
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        $admin->loadMissing('roles:id,key');
        $hasIndustryDirectorRole = $admin->roles
            ->pluck('key')
            ->contains('industry_director');

        $hasActiveAssignment = IndustryDirectorAssignment::query()
            ->where('admin_user_id', $admin->id)
            ->where('is_active', true)
            ->exists();

        if (! $hasIndustryDirectorRole || ! $hasActiveAssignment) {
            abort(403);
        }

        return $next($request);
    }
}
