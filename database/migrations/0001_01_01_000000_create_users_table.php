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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->nullable();
            $table->string('referral_code')->nullable();
            $table->string('referred_by')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('dob')->nullable();
            $table->string('nationality')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('pin_code')->nullable();
            $table->string('language')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->tinyInteger('role')->default(2);
            $table->string('password')->nullable();
            $table->string('pwd_text')->nullable();
            $table->dateTime('last_login')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('training_status')->default(1);
            $table->tinyInteger('current_promoter_level')->nullable(); // 0,1,2,3,4
            $table->tinyInteger('promoter_status')->nullable();       // 0=pending, 1=approved, 2=activated, 3=rejected
            $table->timestamp('promoter_activated_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->string('password_reset_token')->nullable();
            $table->timestamp('password_reset_token_expires_at')->nullable();
            $table->tinyInteger('mobile_verified')->default(0);
            $table->timestamp('mobile_verified_at')->nullable();
            $table->tinyInteger('is_profile_updated')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('mobile')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
