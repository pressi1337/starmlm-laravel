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
        Schema::create('user_training_videos', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable(); // no FK, just reference
            $table->bigInteger('training_video_id')->nullable(); // no FK, just reference
            $table->integer('day')->nullable(); // training day number
            // status: 0 = assigned, 1 = in-progress, 2 = completed
            $table->tinyInteger('status')->default(0)->nullable(); 
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
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
        Schema::dropIfExists('user_training_videos');
    }
};
