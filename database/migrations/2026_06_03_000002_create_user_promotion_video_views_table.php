<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks which promotion video each user has been shown, per day, so the
     * now-random selection can avoid repeats (same day / immediate next day)
     * and stay stable across refreshes for the in-progress slot.
     *
     * - (user_id, viewed_date) drives the no-repeat exclusion.
     * - (user_id, session, set_no, video_order) lets a refresh resolve back to
     *   the SAME video already assigned to the current slot.
     */
    public function up(): void
    {
        Schema::create('user_promotion_video_views', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('user_promoter_id')->nullable();
            $table->integer('user_promoter_session_id')->nullable();
            $table->tinyInteger('set_no')->nullable();       // 1 or 2
            $table->tinyInteger('video_order')->nullable();  // 1..4 within the session
            $table->integer('promotion_video_id');
            $table->date('viewed_date');
            $table->timestamps();

            $table->index(['user_id', 'viewed_date'], 'upvv_user_date_idx');
            $table->index(
                ['user_id', 'user_promoter_session_id', 'set_no', 'video_order'],
                'upvv_slot_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_promotion_video_views');
    }
};
