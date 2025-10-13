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
        Schema::create('additional_scratch_referrals', function (Blueprint $table) {
            $table->id();
            $table->integer('userid')->nullable();
            $table->string('referral_code')->nullable();
            $table->tinyInteger('is_all_user')->default(0);//1-all user, 0-individual user
            $table->tinyInteger('is_active')->default(1);//1-active, 0-inactive
            $table->tinyInteger('is_deleted')->default(0);//1-deleted, 0-not deleted
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('additional_scratch_referrals');
    }
};
