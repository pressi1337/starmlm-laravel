<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit per-set retry counters. Each set allows exactly ONE retry
     * (UserPromoterSession::MAX_RETRIES_PER_SET). The counter is a hard,
     * order-independent cap on top of the existing video-order advance so a
     * set can never grant more than one retry regardless of client behaviour.
     */
    public function up(): void
    {
        Schema::table('user_promoter_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('set1_retry_count')->default(0)->after('set1_watched_at');
            $table->unsignedTinyInteger('set2_retry_count')->default(0)->after('set2_watched_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_promoter_sessions', function (Blueprint $table) {
            $table->dropColumn(['set1_retry_count', 'set2_retry_count']);
        });
    }
};
