<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'jwt' => \App\Http\Middleware\JwtMiddleware::class,
            'userjwt' => \App\Http\Middleware\UserJwtMiddleware::class,
            'subadmin.permission' => \App\Http\Middleware\SubAdminPermission::class,
        ]);

        // Cron-less maintenance: a throttled, traffic-driven sweep that applies
        // the time-based promoter transitions (10-min term raise, 5-day reject)
        // after each response — at most once per minute. Needs no cron and no
        // queue worker; ordinary request traffic drives it.
        $middleware->append(\App\Http\Middleware\PromoterMaintenanceSweep::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized',
                        'code' => 'unauthorized'
                    ], 401);
                }
            }

            if ($e instanceof \Tymon\JWTAuth\Exceptions\JWTException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token error: ' . $e->getMessage(),
                        'code' => 'token_error'
                    ], 401);
                }
            }

            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token has expired',
                        'code' => 'token_expired'
                    ], 401);
                }
            }

            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token is invalid',
                        'code' => 'token_invalid'
                    ], 401);
                }
            }
        });
    })->create();
