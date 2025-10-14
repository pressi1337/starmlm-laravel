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
        Schema::create('scratch_cards', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('child_id')->nullable();
            $table->tinyInteger('is_copy')->default(0);
            $table->decimal('amount', 10, 2)->default(0);

            //  0-pending,1-scratch
            $table->tinyInteger('is_scratched')->default(0);
            $table->longText('notification_msg')->nullable();
            $table->longText('msg')->nullable();
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
        Schema::dropIfExists('scratch_cards');
    }
};
