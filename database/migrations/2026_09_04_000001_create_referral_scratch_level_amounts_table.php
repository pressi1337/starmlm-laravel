<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple cashback amounts per (promoter level, referral level).
     *
     * Until now `referral_scratch_levels.amount` held ONE fixed amount, so
     * every scratch card for a combination paid exactly the same — which
     * defeats the point of a scratch card. This table holds the pool of
     * amounts the admin configures for a combination; one row is picked at
     * random each time a card is created.
     *
     * The parent's `amount`/`msg` columns stay: they mirror the first pool
     * entry and remain the fallback for any combination that has no pool rows
     * yet, so existing configuration keeps paying out untouched after deploy.
     */
    public function up(): void
    {
        Schema::create('referral_scratch_level_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referral_scratch_level_id');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('msg', 255)->nullable();
            // Display/authoring order in the admin screen. Not a weight —
            // the pick is uniform across active rows.
            $table->integer('order_no')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            // The hot path is "give me the live pool for this level".
            $table->index(
                ['referral_scratch_level_id', 'is_active', 'is_deleted'],
                'rsla_level_live_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_scratch_level_amounts');
    }
};
