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
        Schema::create('user_promoter_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable(); // no FK, just reference
            $table->bigInteger('user_promoter_id')->nullable(); // no FK, just reference
            $table->integer('current_video_order_set1')->default(1); // 1 to 2 inside session
            // applicable for promoter 3 and 4
            $table->integer('current_video_order_set2')->default(3); // 3 to 4 inside session
            $table->tinyInteger('session_type')->default(1);  // ex: 1-"12am-12pm",2-"12pm-1pm"       
            // status: 0 = assigned, 1 = in-progress, 2 = completed,3 = expired 
            $table->tinyInteger('session_status')->default(0); 
            // status: 0 = assigned, 1 = video watched, 2 = quiz completed,3 = submitted 
            $table->tinyInteger('set1_status')->default(0); 
             $table->decimal('earned_amount_set1', 10, 2)->default(0); 
            // status: 0 = assigned, 1 = video watched, 2 = quiz completed,3 = submitted 
            $table->tinyInteger('set2_status')->default(0); 
            $table->decimal('earned_amount_set2', 10, 2)->default(0); 
            $table->timestamp('attend_at')->nullable();
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
        Schema::dropIfExists('user_promoter_sessions');
    }
};
