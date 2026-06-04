<?php

namespace App\Http\Middleware;

use App\Models\ScanAppUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScanAppUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof ScanAppUser) {
            return response()->json([
                'success' => false,
                'message' => 'This API is only available for scanner app users.',
            ], 403);
        }

        return $next($request);
    }
}
