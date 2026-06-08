<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the "basic levels" flag to promotion_videos.
     *
     * Promoter levels 0/1/2 only receive videos flagged is_basic_level = 1;
     * levels 3/4 receive any active video regardless of the flag. Default 1 so
     * every existing and new video is visible to all levels until an admin
     * restricts one (toggle off = Promoter L3/L4 only). Toggled from the admin
     * list, independent of create/edit.
     */
    public function up(): void
    {
        Schema::table('promotion_videos', function (Blueprint $table) {
            $table->tinyInteger('is_basic_level')->default(1)->after('session_type');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_videos', function (Blueprint $table) {
            $table->dropColumn('is_basic_level');
        });
    }
};
