<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the "default new-user video" flag to daily_videos.
     *
     * A brand-new user (one who has never completed a daily video on a prior
     * day) is served the row flagged is_default = 1 as their first daily video.
     * This same row is excluded from the random rotation fallback. Only ONE row
     * should carry is_default = 1 at a time — that invariant is enforced in
     * DailyVideoController (setting one default clears the flag on the others),
     * not by a DB constraint, so the admin can freely move the flag around.
     */
    public function up(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->tinyInteger('is_default')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
