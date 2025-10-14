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
use App\Http\Controllers\V1\Api\AdditionalScratchReferralController;
use App\Http\Controllers\V1\Api\WithdrawController;
use App\Http\Controllers\VideoUploadController;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/auth.php';
});
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
Route::middleware('jwt')->prefix('v1')->group(function () {
    // Custom route 
    Route::patch('daily-videos/status-update', [DailyVideoController::class, 'StatusUpdate']);
    Route::patch('training-videos/status-update', [TrainingVideoController::class, 'StatusUpdate']);
    Route::patch('training-video-quizzes/status-update', [TrainingQuizController::class, 'StatusUpdate']);
    Route::patch('promotion-videos/status-update', [PromotionVideoController::class, 'StatusUpdate']);
    Route::patch('promotion-video-quizzes/status-update', [PromotionQuizController::class, 'StatusUpdate']);
    Route::patch('youtube-channels/status-update', [YoutubeController::class, 'StatusUpdate']);
    Route::patch('scratch-setup/status-update', [ScratchSetupController::class, 'StatusUpdate']);
    Route::patch('delete-account', [JwtAuthController::class, 'DeleteAccount']);
    //
    Route::resource('daily-videos', DailyVideoController::class);
    Route::resource('youtube-channels', YoutubeController::class);
    Route::resource('scratch-setup', ScratchSetupController::class);
    Route::resource('training-videos', TrainingVideoController::class);
    Route::resource('training-video-quizzes', TrainingQuizController::class);
    Route::resource('promotion-videos', PromotionVideoController::class);
    Route::resource('promotion-video-quizzes', PromotionQuizController::class);

    // Additional Scratch Referral (admin)
    Route::post('additional-scratch-referrals/upsert', [AdditionalScratchReferralController::class, 'upsert']);
    Route::get('additional-scratch-referrals/{id}', [AdditionalScratchReferralController::class, 'show']);

    Route::post('generate-pin', [UserPromoterController::class, 'generatePin']);
    Route::post('term-raised', [UserPromoterController::class, 'termRaised']);

    // unified endpoint: handles chunk upload and auto-merge
    Route::post('upload', [VideoUploadController::class, 'upload']);
    Route::post('upload/delete', [VideoUploadController::class, 'delete']);
    Route::post('withdraw-status-update', [WithdrawController::class, 'withdrawStatusUpdate']);
    
});

Route::middleware('userjwt')->prefix('v1')->group(function () {
    // role based middleware pending
    Route::get('daily-videos-today', [DailyVideoController::class, 'todayVideo']);
    Route::get('daily-videos-status', [DailyVideoController::class, 'todayVideostatus']);
    Route::post('daily-videos-watched', [DailyVideoController::class, 'todayVideoWatched']);
    
    Route::get('user-training-current', [UserTrainingController::class, 'getCurrentTrainingVideo']);
    Route::post('user-day-training-mark-as-completed', [UserTrainingController::class, 'completeTraining']);
    Route::post('term-accepted', [UserPromoterController::class, 'termrAccepted']);
    Route::post('activate-pin', [UserPromoterController::class, 'activatePin']);
    Route::get('user-promoters/list', [UserPromoterController::class, 'userPromotersList']);
    Route::get('user-promoter-video-get', [PromotionVideoController::class, 'userPromotionVideo']);
    Route::post('user-promoter-quiz-result-get', [PromotionVideoController::class, 'userPromoterQuizResult']);
    Route::post('user-promoter-quiz-result-confirmation', [PromotionVideoController::class, 'userPromoterQuizResultConfirmation']);
    Route::get('earning-histories', [WithdrawController::class, 'earningHistory']);
    Route::get('withdraw-histories', [WithdrawController::class, 'withdrawHistory']);


});

//Common route for both admin and user panel (Option 1: auth with multiple guards)
Route::prefix('v1')->middleware('auth:jwt,userjwt')->group(function () {
    Route::get('auth-user', [JwtAuthController::class, 'AuthUser']);
    Route::patch('changepassword', [JwtAuthController::class, 'changePassword']);
    Route::patch('update-personal-details', [JwtAuthController::class, 'updatePersonalDetails']);
    Route::resource('user-promoters', UserPromoterController::class);
    Route::resource('referrals', ReferralController::class);
    Route::resource('withdraws', WithdrawController::class);
});
