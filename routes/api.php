<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\JwtAuthController;







Route::prefix('v1')->group(function () {
    require __DIR__ . '/auth.php';
});
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
Route::prefix('v1')->group(function () {
    Route::middleware('auth:api')->group(function () {
        Route::get('auth-user', [JwtAuthController::class, 'AuthUser']);
    });
});
