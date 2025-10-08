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
        Schema::create('daily_video_watch_details', function (Blueprint $table) {
            $table->id();
            $table->integer('daily_video_id')->nullable();
            $table->date('watched_date')->nullable();;
            $table->integer('user_id')->nullable();
            $table->tinyInteger('watchedstatus')->default(0);
            $table->decimal('watchedcount', 8, 2)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_video_watch_details');
    }
};
