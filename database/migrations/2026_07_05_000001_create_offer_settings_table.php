<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Points awarded per promoter-level upgrade, one row per option type.
     *
     * option_type: 1 = Own (the upgrading user earns), 2 = Referral (their
     * direct referrer earns). Only ONE active row per option type.
     *
     * Points are keyed by the level the user REACHES — not by a from→to pair,
     * because levels can be skipped (a Promoter 1 can jump straight to
     * Promoter 3 or 4). Whatever level the activated pin grants, that level's
     * points are awarded:
     *   promotor_points  → reached level 0 (Promoter)
     *   promotor1_points → level 1
     *   promotor2_points → level 2
     *   promotor3_points → level 3
     *   promotor4_points → level 4
     */
    public function up(): void
    {
        Schema::create('offer_settings', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('option_type'); // 1 = own, 2 = referral
            $table->decimal('promotor_points', 10, 2)->default(0);
            $table->decimal('promotor1_points', 10, 2)->default(0);
            $table->decimal('promotor2_points', 10, 2)->default(0);
            $table->decimal('promotor3_points', 10, 2)->default(0);
            $table->decimal('promotor4_points', 10, 2)->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['option_type', 'is_deleted'], 'offer_settings_option_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_settings');
    }
};
