<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the "in rotation" flag to daily_videos.
     *
     * On a day with no scheduled (dated) video, the app serves a rotating
     * fallback so users still have something to watch. That fallback draws
     * ONLY from the videos an admin has explicitly flagged with
     * is_rotational = 1 (any number of them) — the rotation pool is
     * admin-curated rather than "every past video". The single default
     * new-user video is always excluded from rotation. Toggled from the admin
     * list, independent of create/edit.
     */
    public function up(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->tinyInteger('is_rotational')->default(0)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->dropColumn('is_rotational');
        });
    }
};
