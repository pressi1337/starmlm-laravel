<?php

namespace App\Services;

use App\Models\PromotionVideo;
use App\Models\User;
use App\Models\UserPromoterSession;
use App\Models\UserPromotionVideoAssignment;
use Carbon\Carbon;

class PromotionVideoAssignmentService
{
    public function __construct(
        protected ReferralTreeService $referralTreeService
    ) {
    }

    public function resolveAssignment(
        User $user,
        UserPromoterSession $session,
        int $sessionType,
        int $setNo,
        int $videoOrderSlot
    ): ?UserPromotionVideoAssignment {
        $attendDate = Carbon::parse($session->attend_at)->toDateString();

        $existingAssignment = $this->findAssignment(
            (int) $user->id,
            (int) $session->id,
            $attendDate,
            $sessionType,
            $setNo,
            $videoOrderSlot
        );

        if ($existingAssignment) {
            return $existingAssignment->load('promotionVideo.quiz.questions.choices');
        }

        $treeRootUserId = $this->referralTreeService->getRootAncestorId((int) $user->id);

        $blockedVideoIds = UserPromotionVideoAssignment::query()
            ->where('tree_root_user_id', $treeRootUserId)
            ->whereDate('attend_date', $attendDate)
            ->where('session_type', $sessionType)
            ->where('is_deleted', 0)
            ->pluck('promotion_video_id');

        $userAssignedVideoIds = UserPromotionVideoAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_deleted', 0)
            ->pluck('promotion_video_id');

        $freshVideo = PromotionVideo::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->whereNotIn('id', $userAssignedVideoIds)
            ->when($blockedVideoIds->isNotEmpty(), function ($query) use ($blockedVideoIds) {
                $query->whereNotIn('id', $blockedVideoIds);
            })
            ->whereHas('quiz')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $selectedVideo = $freshVideo;
        $assignmentType = UserPromotionVideoAssignment::ASSIGNMENT_TYPE_NEW;

        if (!$selectedVideo) {
            $selectedVideo = PromotionVideo::query()
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereHas('quiz')
                ->whereIn('id', $userAssignedVideoIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($selectedVideo) {
                $assignmentType = UserPromotionVideoAssignment::ASSIGNMENT_TYPE_REPLAY;
            }
        }

        if (!$selectedVideo) {
            $selectedVideo = PromotionVideo::query()
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereHas('quiz')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($selectedVideo) {
                $assignmentType = UserPromotionVideoAssignment::ASSIGNMENT_TYPE_REPLAY;
            }
        }

        if (!$selectedVideo) {
            return null;
        }

        $assignment = UserPromotionVideoAssignment::create([
            'user_id' => $user->id,
            'promotion_video_id' => $selectedVideo->id,
            'user_promoter_session_id' => $session->id,
            'tree_root_user_id' => $treeRootUserId,
            'attend_date' => $attendDate,
            'session_type' => $sessionType,
            'set_no' => $setNo,
            'video_order_slot' => $videoOrderSlot,
            'assignment_type' => $assignmentType,
            'assigned_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $assignment->load('promotionVideo.quiz.questions.choices');
    }

    public function findAssignment(
        int $userId,
        int $sessionId,
        string $attendDate,
        int $sessionType,
        int $setNo,
        int $videoOrderSlot
    ): ?UserPromotionVideoAssignment {
        return UserPromotionVideoAssignment::query()
            ->where('user_id', $userId)
            ->where('user_promoter_session_id', $sessionId)
            ->whereDate('attend_date', $attendDate)
            ->where('session_type', $sessionType)
            ->where('set_no', $setNo)
            ->where('video_order_slot', $videoOrderSlot)
            ->where('is_deleted', 0)
            ->latest('id')
            ->first();
    }

    public function markQuizCompleted(UserPromotionVideoAssignment $assignment, int $updatedBy): void
    {
        $assignment->is_watched = 1;
        $assignment->watched_at = $assignment->watched_at ?: now();
        $assignment->is_quiz_completed = 1;
        $assignment->quiz_completed_at = now();
        $assignment->updated_by = $updatedBy;
        $assignment->save();
    }

    public function markConfirmed(UserPromotionVideoAssignment $assignment, int $updatedBy): void
    {
        $assignment->is_confirmed = 1;
        $assignment->confirmed_at = now();
        $assignment->updated_by = $updatedBy;
        $assignment->save();
    }
}
