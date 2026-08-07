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
     * Each column is keyed by the TARGET level of the upgrade:
     *   trainee_to_promotor     → activates at level 0
     *   promotor_to_promotor1   → level 1
     *   promotor1_to_promotor2  → level 2
     *   promotor2_to_promotor3  → level 3
     *   promotor3_to_promotor4  → level 4
     */
    public function up(): void
    {
        Schema::create('offer_settings', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('option_type'); // 1 = own, 2 = referral
            $table->decimal('trainee_to_promotor', 10, 2)->default(0);
            $table->decimal('promotor_to_promotor1', 10, 2)->default(0);
            $table->decimal('promotor1_to_promotor2', 10, 2)->default(0);
            $table->decimal('promotor2_to_promotor3', 10, 2)->default(0);
            $table->decimal('promotor3_to_promotor4', 10, 2)->default(0);
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
