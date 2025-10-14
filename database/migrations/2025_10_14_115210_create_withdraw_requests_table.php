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
        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('request_at')->nullable();
            // 0-pending,1-processing,2-completed,3-rejected
            $table->tinyInteger('status')->default(0);
            // 1-main2=,2-scratch,3-grow
            $table->tinyInteger('wallet_type')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->longText('reason')->nullable();
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
        Schema::dropIfExists('withdraw_requests');
    }
};
