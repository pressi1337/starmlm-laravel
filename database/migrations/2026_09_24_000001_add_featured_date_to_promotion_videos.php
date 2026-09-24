<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One optional "featured" promotion video per date.
     *
     * Promotion videos are normally served at random from the eligible pool.
     * Occasionally the client wants a specific video seen by everyone on a
     * given day — an announcement, a launch. Setting featured_date on a video
     * makes it the FIRST video every user is served that day; once they have
     * been shown it, the rest of their day is the usual random selection.
     *
     * NULL on every row means "no featured video", which is the normal state —
     * the whole feature is opt-in and nothing changes until a date is set.
     *
     * At most one video may hold a given date. That is enforced in
     * PromotionVideoController (setting a date clears it from any other video
     * for that date) rather than by a unique index, because soft-deleted rows
     * live in this table and a unique index would let a deleted video block
     * the date forever.
     */
    public function up(): void
    {
        Schema::table('promotion_videos', function (Blueprint $table) {
            $table->date('featured_date')->nullable()->after('is_basic_level');
            // Looked up once per video request: "is anything featured today?"
            $table->index(['featured_date', 'is_active', 'is_deleted'], 'pv_featured_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_videos', function (Blueprint $table) {
            $table->dropIndex('pv_featured_idx');
            $table->dropColumn('featured_date');
        });
    }
};
