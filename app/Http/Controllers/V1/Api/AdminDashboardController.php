<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPromoter;
use App\Models\DailyVideo;
use App\Models\PromotionVideo;
use App\Models\YoutubeChannel;
use App\Models\TrainingVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Last 3 users with role 2 (ROLE_USER) in descending ID order
            $recentUsers = User::where('role', User::ROLE_USER)
                ->where('is_deleted', 0)
                ->orderBy('id', 'desc')
                ->limit(3)
                ->get(['id', 'username', 'email', 'created_at']);

            // Total counts
            $totalDailyVideos = DailyVideo::where('is_deleted', 0)->count();
            $totalPromotionVideos = PromotionVideo::where('is_deleted', 0)->count();
            $totalYoutubeChannels = YoutubeChannel::where('is_deleted', 0)->count();
            $totalTrainingVideos = TrainingVideo::where('is_deleted', 0)->count();

            // User management data
            $totalUsers = User::where('role', User::ROLE_USER)->where('is_deleted', 0)->count();
            $totalActiveUsers = User::where('role', User::ROLE_USER)
                ->where('is_deleted', 0)
                ->where('is_active', 1)
                ->count();
            $newUsersToday = User::where('role', User::ROLE_USER)
                ->where('is_deleted', 0)
                ->whereDate('created_at', today())
                ->count();
            $newUsersThisMonth = User::where('role', User::ROLE_USER)
                ->where('is_deleted', 0)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            // Promoter status counts (assuming UserPromoter model has status field)
            $pendingPromoters = UserPromoter::where('is_deleted', 0)
                ->where('status', UserPromoter::PIN_STATUS_PENDING)
                ->count();
            $approvedPromoters = UserPromoter::where('is_deleted', 0)
                ->where('status', UserPromoter::PIN_STATUS_APPROVED)
                ->count();
            $activatedPromoters = UserPromoter::where('is_deleted', 0)
                ->where('status', UserPromoter::PIN_STATUS_ACTIVATED)
                ->count();
            $rejectedPromoters = UserPromoter::where('is_deleted', 0)
                ->where('status', UserPromoter::PIN_STATUS_REJECTED)
                ->count();

            $data = [
                'recent_users' => $recentUsers,
                'total_daily_videos' => $totalDailyVideos,
                'total_promotion_videos' => $totalPromotionVideos,
                'total_youtube_channels' => $totalYoutubeChannels,
                'total_training_videos' => $totalTrainingVideos,
                'total_users' => $totalUsers,
                'total_active_users' => $totalActiveUsers,
                'new_users_today' => $newUsersToday,
                'new_users_this_month' => $newUsersThisMonth,
                'pending_promoters' => $pendingPromoters,
                'approved_promoters' => $approvedPromoters,
                'activated_promoters' => $activatedPromoters,
                'rejected_promoters' => $rejectedPromoters,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Admin dashboard data retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard API failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Something went wrong', 'status' => 500], 500);
        }
    }
}
