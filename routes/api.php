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
use App\Http\Controllers\V1\Api\UserBankDetailController;
use App\Http\Controllers\V1\Api\AdminBankDetailController;
use App\Http\Controllers\V1\Api\AdditionalScratchReferralController;
use App\Http\Controllers\V1\Api\WithdrawController;
use App\Http\Controllers\VideoUploadController;
use App\Http\Controllers\V1\Api\AdminDashboardController;
use App\Http\Controllers\V1\Api\LevelIncomeRuleController;
use App\Http\Controllers\V1\Api\SupportHelpController;
use App\Http\Controllers\V1\Api\UserSuggestionController;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/auth.php';
});
Route::get('/login', function () {
    return response()->json(['message' => 'Please log in.'], 401);
})->name('login');
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
    Route::patch('level-income-rules/status-update', [LevelIncomeRuleController::class, 'statusUpdate']);
    Route::patch('support-help/status-update', [SupportHelpController::class, 'statusUpdate']);
    Route::patch('delete-account', [JwtAuthController::class, 'DeleteAccount']);
    //
    Route::resource('daily-videos', DailyVideoController::class);
    Route::resource('level-income-rules', LevelIncomeRuleController::class);
    Route::resource('scratch-setup', ScratchSetupController::class);
    Route::resource('training-videos', TrainingVideoController::class);
    Route::resource('training-video-quizzes', TrainingQuizController::class);
    Route::resource('promotion-videos', PromotionVideoController::class);
    Route::resource('promotion-video-quizzes', PromotionQuizController::class);

    // Additional Scratch Referral (admin)
    Route::post('additional-scratch-referrals/upsert', [AdditionalScratchReferralController::class, 'upsert']);
    Route::get('additional-scratch-referrals', [AdditionalScratchReferralController::class, 'show']);

    Route::post('generate-pin', [UserPromoterController::class, 'generatePin']);
    Route::post('term-raised', [UserPromoterController::class, 'termRaised']);
    Route::post('pin-rejected', [UserPromoterController::class, 'pinRejected']);
    Route::post('pin-requests/product-delivery-status-update', [UserPromoterController::class, 'productDeliveryStatusUpdate']);
    Route::get('pin-requests/export/excel', [UserPromoterController::class, 'exportExcel']);

    // unified endpoint: handles chunk upload and auto-merge
    Route::post('upload', [VideoUploadController::class, 'upload']);
    Route::post('upload/delete', [VideoUploadController::class, 'delete']);
    Route::post('withdraw-status-update', [WithdrawController::class, 'withdrawStatusUpdate']);
    Route::post('withdraws/import/excel', [WithdrawController::class, 'importExcel']);
    // Admin Dashboard
    Route::get('admin-dashboard', [AdminDashboardController::class, 'index']);
    
    // Admin Bank Details
    Route::post('admin-bank-details/upsert', [AdminBankDetailController::class, 'manage']);
    Route::get('admin-bank-details', [AdminBankDetailController::class, 'getActive']);
    Route::post('user-bank-detail/admin-reset', [UserBankDetailController::class, 'adminReset']);
    Route::post('user-suggestions/react', [UserSuggestionController::class, 'react']);
    Route::post('support-help', [SupportHelpController::class, 'store']);
    Route::put('support-help/{id}', [SupportHelpController::class, 'update']);
    Route::delete('support-help/{id}', [SupportHelpController::class, 'destroy']);
    Route::get('support-help/{id}', [SupportHelpController::class, 'show']);
    
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
    Route::post('pin-requests/customer-delivery-confirmation', [UserPromoterController::class, 'customerDeliveryConfirmation']);
    Route::get('user-promoters/list', [UserPromoterController::class, 'userPromotersList']);
    Route::get('user-promoter-video-get', [PromotionVideoController::class, 'userPromotionVideo']);
    Route::post('user-promoter-quiz-result-get', [PromotionVideoController::class, 'userPromoterQuizResult']);
    Route::post('user-promoter-quiz-result-confirmation', [PromotionVideoController::class, 'userPromoterQuizResultConfirmation']);
    Route::get('earning-histories', [WithdrawController::class, 'earningHistory']);
    Route::get('withdraw-histories', [WithdrawController::class, 'withdrawHistory']);

    // User bank detail upsert and fetch
    Route::post('user-bank-detail/upsert', [UserBankDetailController::class, 'upsert']);
    Route::get('user-bank-detail', [UserBankDetailController::class, 'show']);
    Route::post('user-suggestions', [UserSuggestionController::class, 'store']);

    // scratch cards
    Route::get('get-scratch-cards', [UserPromoterController::class, 'getScratchCards']);
    Route::post('scratched-status-update', [UserPromoterController::class, 'scratchedStatusUpdate']);

    // Dashboard API
    Route::get('user-dashboard', [UserPromoterController::class, 'dashboard']);

});

//Common route for both admin and user panel (Option 1: auth with multiple guards)
Route::prefix('v1')->middleware('auth:jwt,userjwt')->group(function () {
    Route::get('auth-user', [JwtAuthController::class, 'AuthUser']);
    Route::patch('changepassword', [JwtAuthController::class, 'changePassword']);
    Route::patch('update-personal-details', [JwtAuthController::class, 'updatePersonalDetails']);
    Route::get('referrals/team-summary', [ReferralController::class, 'teamSummary']);
    Route::get('referrals/{id}/team-details', [ReferralController::class, 'userTeamDetails']);
    Route::resource('user-promoters', UserPromoterController::class);
    Route::resource('referrals', ReferralController::class);
    Route::get('all-referrals', [ReferralController::class, 'allReferral']);
    Route::resource('withdraws', WithdrawController::class);
    Route::get('referrals/export/excel', [ReferralController::class, 'exportExcel']);
    Route::get('withdraws/export/excel', [WithdrawController::class, 'exportExcel']);
    Route::resource('youtube-channels', YoutubeController::class);
    Route::get('admin-bank-details', [AdminBankDetailController::class, 'getActive']);
    Route::get('support-help', [SupportHelpController::class, 'index']);
    Route::get('user-suggestions', [UserSuggestionController::class, 'index']);
});
