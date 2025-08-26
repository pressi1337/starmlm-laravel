<?php

use App\Http\Controllers\Auth\JwtAuthController;
use Illuminate\Support\Facades\Route;



// api based login
Route::post('/auth/login', [JwtAuthController::class, 'login']);
Route::post('/auth/register', [JwtAuthController::class, 'register']);
// Route::post('/forgot-password', SendPasswordResetLinkController::class)
//     ->name('password.email');

// Route::post('/reset-password', ResetPasswordController::class)
//     ->name('password.update');

Route::middleware('auth:api')->group(function () {

    Route::post('/api-logout', [JwtAuthController::class, 'logout']);
});

