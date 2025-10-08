<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

class UserJwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Attempt to authenticate the user via JWT
            $user = JWTAuth::parseToken()->authenticate();

            // Check if user exists and meets conditions
            if (!$user || $user->role !== 2 || $user->is_deleted !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'unauthorized',
                    'error' => [
                        'general' => 'Unauthorized'
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