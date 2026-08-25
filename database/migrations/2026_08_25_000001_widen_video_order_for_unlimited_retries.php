<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retries are now unlimited, and each retry advances the video order by one
     * so the user gets a fresh video. `video_order` was a TINYINT (max 127), so
     * a session with enough retries would overflow and the insert in
     * resolvePromotionVideoId would fail — blocking the user entirely.
     *
     * SMALLINT gives plenty of headroom for a single session's retries.
     */
    public function up(): void
    {
        Schema::table('user_promotion_video_views', function (Blueprint $table) {
            $table->smallInteger('video_order')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_promotion_video_views', function (Blueprint $table) {
            $table->tinyInteger('video_order')->nullable()->change();
        });
    }
};
