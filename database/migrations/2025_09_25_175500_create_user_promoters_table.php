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
        Schema::create('user_promoters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();       // User reference
            $table->tinyInteger('level')->nullable();                // Promoter level (0,1,2,3,4)

            // Optional user details required when starting promoter
            $table->tinyInteger('gift_delivery_type')->nullable();  //1-pic from shop ,2-place delivery
            $table->date('direct_pick_date')->nullable();
            $table->text('gift_delivery_address')->nullable();
            $table->string('wh_number')->nullable();
            // Control fields
            $table->string('pin')->nullable();                       // Admin generated PIN
            $table->tinyInteger('status')->default(0);              // 0=pending, 1=approved, 2=activated, 3=rejected
            $table->timestamp('pin_generated_at')->nullable();      // When PIN was created
            $table->timestamp('activated_at')->nullable();          // When user activated
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
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
        Schema::dropIfExists('user_promoters');
    }
};
