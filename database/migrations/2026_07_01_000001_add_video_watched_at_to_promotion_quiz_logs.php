<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the video for this attempt was watched (copied from the session's
     * setN_watched_at at quiz-submit time). Gives the audit a real "watched at"
     * distinct from "attempted at". Null for attempts logged before this.
     */
    public function up(): void
    {
        Schema::table('promotion_quiz_logs', function (Blueprint $table) {
            $table->timestamp('video_watched_at')->nullable()->after('attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_quiz_logs', function (Blueprint $table) {
            $table->dropColumn('video_watched_at');
        });
    }
};
