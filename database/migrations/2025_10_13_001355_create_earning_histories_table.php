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
        Schema::create('earning_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->date('earning_date')->nullable();
            // 1-session1 set1 video,2-session1 set2 video,3-session2 set1 video,
            // 4-session2 set2 video ,5-scratch earning,6-savings earning
            $table->tinyInteger('earning_type')->default(1);
            $table->tinyInteger('earning_status')->default(0);
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
        Schema::dropIfExists('earning_histories');
    }
};
