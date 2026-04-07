<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_income_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('promoter_level');
            $table->integer('referral_depth');
            $table->decimal('amount', 10, 2)->default(0);
            $table->tinyInteger('trigger_type')->default(1);
            $table->tinyInteger('wallet_type')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['promoter_level', 'referral_depth', 'trigger_type', 'wallet_type'], 'level_income_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_income_rules');
    }
};
