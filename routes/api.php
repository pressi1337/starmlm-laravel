<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\JwtAuthController;
use App\Http\Controllers\V1\Api\DailyVideoController;
use App\Http\Controllers\V1\Api\YoutubeController;
use App\Http\Controllers\V1\Api\ScratchSetupController;
use App\Http\Controllers\V1\Api\TrainingVideoController;
use App\Http\Controllers\V1\Api\TrainingQuizController;
use App\Http\Controllers\V1\Api\PromotionVideoController;
use App\Http\Controllers\V1\Api\PromotionQuizController;
use App\Http\Controllers\V1\Api\ReferralController;
use App\Http\Controllers\V1\Api\UserPromoterController;
use App\Http\Controllers\V1\Api\UserTrainingController;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/auth.php';
});
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
Route::middleware('jwt')->prefix('v1')->group(function () {
    Route::resource('daily-videos', DailyVideoController::class);
    Route::resource('youtube-channels', YoutubeController::class);
    Route::resource('scratch-setup', ScratchSetupController::class);
    Route::resource('training-videos', TrainingVideoController::class);
    Route::resource('training-video-quizzes', TrainingQuizController::class);
    Route::resource('promotion-videos', PromotionVideoController::class);
    Route::resource('promotion-video-quizzes', PromotionQuizController::class);
    Route::get('auth-user', [JwtAuthController::class, 'AuthUser']);
    Route::post('generate-pin', [UserPromoterController::class,'generatePin']);


    //commmon both admin and user panel
    // using this store method for user promoter registration
    Route::resource('user-promoters', UserPromoterController::class);
    
    
    // user panel based apis later we want to add middleware
    Route::get('daily-videos-today', [DailyVideoController::class,'todayVideo']);
    Route::resource('referrals', ReferralController::class);
    Route::get('user-training-current', [UserTrainingController::class,'getCurrentTrainingVideo']);
    Route::post('user-day-training-mark-as-completed', [UserTrainingController::class,'completeTraining']);
    Route::post('activate-pin', [UserPromoterController::class,'activatePin']);
    Route::get('user-promoters/list', [UserPromoterController::class, 'userPromotersList']);
});

Route::prefix('userjwt')->prefix('v1')->group(function () {
    // role based middleware pending

});
