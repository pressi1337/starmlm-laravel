<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offer points ledger — one row per award. All totals (user cards, admin
     * tab 2, leaderboard) are aggregated from here, so history is free.
     *
     *   user_id        — who earned the points.
     *   source_user_id — whose upgrade triggered it (= user_id for "own",
     *                    the referred child's id for "referral").
     *   option_type    — 1 = own upgrade, 2 = referral upgrade.
     *   level          — TARGET promoter level of the upgrade (0..4).
     */
    public function up(): void
    {
        Schema::create('offer_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_user_id')->nullable();
            $table->unsignedBigInteger('user_promoter_id')->nullable();
            $table->tinyInteger('option_type');
            $table->tinyInteger('level')->nullable();
            $table->decimal('points', 10, 2)->default(0);
            $table->string('description')->nullable();
            $table->dateTime('earned_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_deleted'], 'offer_points_user_idx');
            $table->index(['user_id', 'option_type'], 'offer_points_user_option_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_points');
    }
};
