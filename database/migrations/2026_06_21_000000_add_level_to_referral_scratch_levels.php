<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the referral-depth "level" to the scratch cashback config.
     *
     * Each cashback config row is now keyed by (promotor_level, level):
     *   - promotor_level (0-4) = the activating user's promoter level (existing)
     *   - level (1-7)          = referral depth — 1 is the direct referrer,
     *                            2 is their referrer, ... up to 7.
     *
     * Existing rows become level = 1 (the original direct-referral config), so
     * nothing breaks and the "Level 1" tab shows today's data.
     */
    public function up(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->integer('level')->default(1)->after('promotor_level');
        });
    }

    public function down(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
