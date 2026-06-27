<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log for promotion-video quiz attempts.
     *
     * One row per quiz submission (`userPromoterQuizResult`). Captures who took
     * which video's quiz, when, the per-question answers, the score, the earning
     * calculated, whether a retry was offered, and the final outcome (the user
     * either confirmed the result or retried — a retry produces a new row and
     * flips the previous attempt to "retried"). Admin reviews these on the
     * Promotion Log page to audit how users are performing.
     */
    public function up(): void
    {
        Schema::create('promotion_quiz_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('promotion_video_id')->nullable();
            // Snapshot the title so the log stays readable if a video is edited
            // or removed later.
            $table->string('promotion_video_title')->nullable();
            $table->unsignedBigInteger('user_promoter_id')->nullable();
            $table->unsignedBigInteger('user_promoter_session_id')->nullable()->index();
            $table->tinyInteger('promoter_level')->nullable();
            // 1 = morning, 2 = evening.
            $table->tinyInteger('session_type')->nullable();
            // 1 = set 1, 2 = set 2.
            $table->tinyInteger('set_no')->nullable();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('earned_amount', 10, 2)->default(0);
            // Was the user offered a "Retry Quiz" option for this attempt.
            $table->tinyInteger('offered_retry')->default(0);
            // 1 = attempted (awaiting confirm/retry), 2 = confirmed, 3 = retried.
            $table->tinyInteger('status')->default(1);
            // Full per-question audit: [{question_id, question, choice_id,
            // chosen_answer, is_correct, correct_choice_id, correct_answer}, ...]
            $table->longText('answers')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_quiz_logs');
    }
};
