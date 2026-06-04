<?php

use App\Exceptions\QrGenerationException;
use App\Http\Middleware\AdminCircleScope;
use App\Http\Middleware\AdminRoleMiddleware;
use App\Http\Middleware\AllowFixedMembersToken;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\EnsureDedApiAccess;
use App\Http\Middleware\EnsureIndustryDirector;
use App\Http\Middleware\EnsureScanAppUser;
use App\Http\Middleware\EnsureUnityUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.auth' => EnsureAdminAuthenticated::class,
            'admin.role' => AdminRoleMiddleware::class,
            'admin.industry-director' => EnsureIndustryDirector::class,
            'admin.circle' => AdminCircleScope::class,
            'fixed.members.token' => AllowFixedMembersToken::class,
            'ensure.ded.api' => EnsureDedApiAccess::class,
            'scan.app.user' => EnsureScanAppUser::class,
            'unity.user' => EnsureUnityUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $throwable): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof QrGenerationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'data' => null,
                    'meta' => null,
                ], 422);
            }

            $statusCode = method_exists($e, 'getStatusCode')
                ? $e->getStatusCode()
                : 500;

            return response()->json([
                'success' => false,
                'status' => false,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'data' => null,
                'meta' => null,
            ], $statusCode);
        });
    })->create();
