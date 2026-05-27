<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Attempt to authenticate the user via JWT
            $user = JWTAuth::parseToken()->authenticate();

            // Admin guard: both super-admin (0) and sub-admin (1) authenticate here.
            // Per-route role gating (e.g. role:0) further restricts super-admin-only routes.
            $allowedRoles = [User::ROLE_SUPER_ADMIN, User::ROLE_SUB_ADMIN];
            if (!$user || !in_array($user->role, $allowedRoles, true) || $user->is_deleted !== 0 || $user->is_active !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'unauthorized',
                    'error' => [
                        'general' => 'Unauthorized'
                    ]
                ], 401);
            }

            // Verify session version using JTI
            $payload = JWTAuth::getPayload();
            if ($payload->get('jti') !== $user->remember_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session expired. Please login again.',
                    'code' => 'session_expired',
                    'error' => [
                        'session' => 'New login detected on another system.'
                    ]
                ], 401);
            }

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired',
                'code' => 'token_expired',
                'error' => [
                    'token' => 'Token has expired'
                ]
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token',
                'code' => 'invalid_token',
                'error' => [
                    'token' => 'Invalid token'
                ]
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided or malformed',
                'code' => 'token_missing',
                'error' => [
                    'token' => 'Token not provided or malformed'
                ]
            ], 401);
        }

        // If authentication is successful, proceed to the next request
        return $next($request);
    }
}