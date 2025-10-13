<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_scratch_ranges');
    }
};
