<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop referral_scratch_ranges.
     *
     * The range concept ("1-5 referrals pay X, 6-10 pay Y") was retired in
     * 2026_06_22_000000_add_amount_msg_to_referral_scratch_levels, which
     * copied each level's first range onto referral_scratch_levels.amount and
     * left the table behind. Since then nothing has read it and nothing has
     * ever written to it — the only remaining reference was a soft-delete
     * cascade in ScratchSetupController::destroy, which is now gone too.
     *
     * The payout amounts live on referral_scratch_levels (single amount) and
     * referral_scratch_level_amounts (the random pool), so no data is lost.
     *
     * IMPORTANT: back the table up before running this against production if
     * you want the pre-June-2026 range configuration kept for reference —
     * see phase3sqlchanges.txt for the CREATE TABLE ... SELECT statement.
     */
    public function up(): void
    {
        Schema::dropIfExists('referral_scratch_ranges');
    }

    /**
     * Recreates the table (empty) so a rollback leaves the schema intact.
     * Nothing reads it, so an empty table is functionally identical to the
     * populated one.
     */
    public function down(): void
    {
        if (Schema::hasTable('referral_scratch_ranges')) {
            return;
        }

        Schema::create('referral_scratch_ranges', function (Blueprint $table) {
            $table->id();
            $table->integer('referral_scratch_level_id')->nullable();
            $table->integer('start_range')->default(0);
            $table->integer('end_range')->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('msg')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('order_no')->default(1);
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();
        });
    }
};
