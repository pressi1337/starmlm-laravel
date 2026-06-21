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
use App\Http\Controllers\V1\Api\BoxRequestController;
use App\Http\Controllers\V1\Api\UserTrainingController;
use App\Http\Controllers\V1\Api\UserBankDetailController;
use App\Http\Controllers\V1\Api\AdminBankDetailController;
use App\Http\Controllers\V1\Api\AdditionalScratchReferralController;
use App\Http\Controllers\V1\Api\WithdrawController;
use App\Http\Controllers\VideoUploadController;
use App\Http\Controllers\V1\Api\AdminDashboardController;
use App\Http\Controllers\V1\Api\SubAdminController;
use App\Http\Controllers\V1\Api\SupportHelpController;
use App\Http\Controllers\V1\Api\SuggestionController;
use App\Http\Controllers\V1\Api\TermsAndConditionController;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/auth.php';
});
Route::get('/login', function () {
    return response()->json(['message' => 'Please log in.'], 401);
})->name('login');
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

// Admin endpoints shared by Super-Admin (role=0) and Sub-Admin (role=1).
// For sub-admin, each surface is further gated by a per-feature permission
// flag — super-admin auto-passes the subadmin.permission middleware.
// Pin operations additionally enforce promoter level 0/1 inside the
// controller.
Route::middleware('jwt')->prefix('v1')->group(function () {
    // Daily Videos — requires can_daily_videos for sub-admin.
    // DELETE is split out to super-admin only (see below).
    Route::middleware('subadmin.permission:daily_videos')->group(function () {
        Route::patch('daily-videos/status-update', [DailyVideoController::class, 'StatusUpdate']);
        // On/off toggle for the single "default new-user video" — set straight
        // from the list, independent of create/edit. Declared before the
        // resource so the {daily_video} slot doesn't swallow it.
        Route::patch('daily-videos/default-update', [DailyVideoController::class, 'defaultUpdate']);
        // On/off toggle for membership of the daily rotation pool. Any number
        // of videos can be flagged. Also before the resource for the same
        // route-collision reason.
        Route::patch('daily-videos/rotational-update', [DailyVideoController::class, 'rotationalUpdate']);
        Route::resource('daily-videos', DailyVideoController::class)->except(['destroy']);
    });

    // Promotion Videos + their quizzes — requires can_promotion_videos.
    // DELETE is split out to super-admin only (see below).
    Route::middleware('subadmin.permission:promotion_videos')->group(function () {
        Route::patch('promotion-videos/status-update', [PromotionVideoController::class, 'StatusUpdate']);
        // On/off toggle for "Basic (L0-L2)" eligibility. Any number of videos
        // can be flagged. Declared before the resource to avoid collision.
        Route::patch('promotion-videos/basic-level-update', [PromotionVideoController::class, 'basicLevelUpdate']);
        Route::patch('promotion-video-quizzes/status-update', [PromotionQuizController::class, 'StatusUpdate']);
        Route::resource('promotion-videos', PromotionVideoController::class)->except(['destroy']);
        Route::resource('promotion-video-quizzes', PromotionQuizController::class)->except(['destroy']);
    });

    // Destructive deletes on these resources are super-admin only. Even a
    // sub-admin with the matching permission must not be able to wipe rows
    // via direct API call.
    Route::middleware('role:0')->group(function () {
        Route::delete('daily-videos/{daily_video}', [DailyVideoController::class, 'destroy']);
        Route::delete('promotion-videos/{promotion_video}', [PromotionVideoController::class, 'destroy']);
        Route::delete('promotion-video-quizzes/{promotion_video_quiz}', [PromotionQuizController::class, 'destroy']);
    });

    // Pin lifecycle — requires can_pin_requests AND (for sub-admin) the
    // promoter level 0/1 controller check.
    Route::middleware('subadmin.permission:pin_requests')->group(function () {
        Route::post('generate-pin', [UserPromoterController::class, 'generatePin']);
        Route::post('term-raised', [UserPromoterController::class, 'termRaised']);
        Route::post('pin-rejected', [UserPromoterController::class, 'pinRejected']);

        // Promoter box (product) fulfilment — admin lists requests and marks
        // them Sent. mark-sent declared before the listing for clarity.
        Route::patch('admin-box-requests/mark-sent', [BoxRequestController::class, 'markSent']);
        Route::patch('admin-box-requests/mark-delivered', [BoxRequestController::class, 'adminMarkDelivered']);
        Route::patch('admin-box-requests/update-quantity', [BoxRequestController::class, 'adminUpdateQuantity']);
        Route::get('admin-box-requests', [BoxRequestController::class, 'adminIndex']);
    });

    // Suggestions — admin read-only listing + mark-as-read action.
    // Super-admin auto-passes the subadmin.permission middleware; sub-admin
    // needs the explicit can_suggestions flag. mark-read declared before the
    // implicit show route to avoid collision.
    Route::middleware('subadmin.permission:suggestions')->group(function () {
        Route::patch('admin-suggestions/mark-read', [SuggestionController::class, 'markRead']);
        Route::get('admin-suggestions', [SuggestionController::class, 'adminIndex']);
    });

    // Chunked uploads needed for the video features above. Open to any admin
    // so a sub-admin granted only Promotion Videos can still upload assets.
    Route::post('upload', [VideoUploadController::class, 'upload']);
    Route::post('upload/delete', [VideoUploadController::class, 'delete']);

    // Dashboard stats are non-sensitive counts; both roles need them for the
    // landing page and the PinRequests header tiles.
    Route::get('admin-dashboard', [AdminDashboardController::class, 'index']);
});

// Super-Admin only. Sub-Admin (role=1) gets a 403 from RoleMiddleware here.
Route::middleware(['jwt', 'role:0'])->prefix('v1')->group(function () {
    // Training Videos and their quizzes
    Route::patch('training-videos/status-update', [TrainingVideoController::class, 'StatusUpdate']);
    Route::patch('training-video-quizzes/status-update', [TrainingQuizController::class, 'StatusUpdate']);
    Route::resource('training-videos', TrainingVideoController::class);
    Route::resource('training-video-quizzes', TrainingQuizController::class);

    // YouTube channels (admin manage), Scratch setup, Withdraws, Dashboard, Bank details
    Route::patch('youtube-channels/status-update', [YoutubeController::class, 'StatusUpdate']);
    Route::patch('scratch-setup/status-update', [ScratchSetupController::class, 'StatusUpdate']);
    Route::patch('delete-account', [JwtAuthController::class, 'DeleteAccount']);
    Route::resource('scratch-setup', ScratchSetupController::class);

    // Additional Scratch Referral (admin)
    Route::post('additional-scratch-referrals/upsert', [AdditionalScratchReferralController::class, 'upsert']);
    Route::get('additional-scratch-referrals', [AdditionalScratchReferralController::class, 'show']);

    Route::post('withdraw-status-update', [WithdrawController::class, 'withdrawStatusUpdate']);

    // Admin Bank Details
    Route::post('admin-bank-details/upsert', [AdminBankDetailController::class, 'manage']);
    Route::get('admin-bank-details', [AdminBankDetailController::class, 'getActive']);

    // Sub-Admin management (super-admin manages sub-admins)
    Route::patch('sub-admins/status-update', [SubAdminController::class, 'statusUpdate']);
    Route::resource('sub-admins', SubAdminController::class);

    // Multi-level referral tree drill-down for the admin User Management page.
    Route::get('referral-tree/{userId}', [ReferralController::class, 'referralTree']);

    // Support & Help Q&A — admin CRUD. status-update is declared before the
    // resource so it doesn't get swallowed by the {id} catch-all. The
    // numeric where() on the {support_help} param stops the show/update/
    // destroy slot from shadowing the user-side `support-helps/list` route
    // declared in the userjwt group below.
    Route::patch('support-helps/status-update', [SupportHelpController::class, 'statusUpdate']);
    Route::resource('support-helps', SupportHelpController::class)
        ->where(['support_help' => '[0-9]+']);

    // Admin clear-bank action — wipes a user's locked bank details so they
    // can re-enter them. Body carries `user_id`; POST verb matches the
    // existing user-bank-detail/upsert style.
    Route::post('user-bank-detail/clear', [UserBankDetailController::class, 'clearForUser']);

    // Terms & Conditions — admin saves the single document via upsert.
    Route::post('terms-and-conditions/upsert', [TermsAndConditionController::class, 'upsert']);
});

// Public T&C read endpoint — usable by the PWA reader and also reachable
// pre-login (e.g. from a registration acceptance screen). The content is
// public by design; gating it would block the registration flow.
Route::prefix('v1')->group(function () {
    Route::get('terms-and-conditions', [TermsAndConditionController::class, 'show']);
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

    // User bank detail upsert and fetch
    Route::post('user-bank-detail/upsert', [UserBankDetailController::class, 'upsert']);
    Route::get('user-bank-detail', [UserBankDetailController::class, 'show']);

    // scratch cards
    Route::get('get-scratch-cards', [UserPromoterController::class, 'getScratchCards']);
    Route::post('scratched-status-update', [UserPromoterController::class, 'scratchedStatusUpdate']);

    // Promoter boxes — the user's own list, a "request more" action (manual
    // levels 3/4, within the cap), and confirming delivery.
    Route::get('box-requests/list', [BoxRequestController::class, 'userBoxRequests']);
    Route::post('box-requests/request', [BoxRequestController::class, 'requestBoxes']);
    Route::post('box-requests/delivered', [BoxRequestController::class, 'markDelivered']);

    // Dashboard API
    Route::get('user-dashboard', [UserPromoterController::class, 'dashboard']);

    // Support & Help — active Q&A list for the PWA accordion. Read-only,
    // ordered by id ASC (admin-insertion order).
    Route::get('support-helps/list', [SupportHelpController::class, 'userList']);

    // Suggestions — user CRUD on their own suggestions. Hard-cap of 3
    // unread enforced in the controller. Edit/delete blocked once admin
    // marks read.
    Route::resource('suggestions', SuggestionController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->where(['suggestion' => '[0-9]+']);

});

//Common route for both admin and user panel (Option 1: auth with multiple guards)
Route::prefix('v1')->middleware('auth:jwt,userjwt')->group(function () {
    Route::get('auth-user', [JwtAuthController::class, 'AuthUser']);
    Route::patch('changepassword', [JwtAuthController::class, 'changePassword']);
    Route::patch('update-personal-details', [JwtAuthController::class, 'updatePersonalDetails']);
    Route::get('user-promoters/export/excel', [UserPromoterController::class, 'exportExcel']);
    Route::resource('user-promoters', UserPromoterController::class);
    Route::resource('referrals', ReferralController::class);
    Route::get('all-referrals', [ReferralController::class, 'allReferral']);
    Route::get('all-referrals/export/excel', [ReferralController::class, 'exportExcel']);
    Route::get('withdraws/export/excel', [WithdrawController::class, 'exportExcel']);
    Route::get('withdraws/export/filtered/excel', [WithdrawController::class, 'exportFilteredExcel']);
    Route::resource('withdraws', WithdrawController::class);
    Route::resource('youtube-channels', YoutubeController::class);
    Route::get('admin-bank-details', [AdminBankDetailController::class, 'getActive']);
});
