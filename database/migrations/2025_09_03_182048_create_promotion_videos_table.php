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
        Schema::create('promotion_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('video_path')->nullable();
            $table->string('youtube_link')->nullable();
            $table->date('showing_date')->nullable();
            $table->tinyInteger('video_order')->default(1); // 1 to 4 inside session
            $table->tinyInteger('session_type')->default(1);  // ex: 1-"12am-12pm",2-"12pm-1pm"        
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
        Schema::dropIfExists('promotion_videos');
    }
};
