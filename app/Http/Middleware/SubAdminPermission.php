<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-permission gate for routes that sub-admins must opt into.
 * Super-admin always passes; sub-admin must have the matching flag set on
 * their user row (see User::PERMISSION_COLUMNS).
 *
 * Usage: Route::middleware('subadmin.permission:daily_videos')->group(...).
 *
 * Runs after the `jwt` middleware so Auth::user() is populated.
 */
class SubAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (!$user || !$user->hasAdminPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
                'code'    => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
