<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_promotion_video_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('promotion_video_id');
            $table->unsignedBigInteger('user_promoter_session_id')->nullable();
            $table->unsignedBigInteger('tree_root_user_id')->nullable();
            $table->date('attend_date');
            $table->tinyInteger('session_type')->default(1);
            $table->tinyInteger('set_no')->default(1);
            $table->integer('video_order_slot')->default(1);
            $table->string('assignment_type', 20)->default('new');
            $table->tinyInteger('is_watched')->default(0);
            $table->tinyInteger('is_quiz_completed')->default(0);
            $table->tinyInteger('is_confirmed')->default(0);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('quiz_completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'attend_date', 'session_type', 'set_no', 'video_order_slot'], 'upva_user_session_slot_idx');
            $table->index(['tree_root_user_id', 'attend_date', 'session_type'], 'upva_tree_day_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_promotion_video_assignments');
    }
};
