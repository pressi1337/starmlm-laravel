<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of successful logins — who signed in, when, and from what.
     *
     * username / role are snapshots taken at login time so the log stays
     * readable even if the account is later renamed or soft-deleted.
     *
     * The parsed device / os / browser columns exist so the admin list can be
     * searched and read at a glance; the raw user_agent is kept as the source
     * of truth in case a device isn't recognised.
     */
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('customer_id', 20)->nullable();
            // Snapshot of users.role: 0 super-admin, 1 sub-admin, 2 user.
            $table->tinyInteger('role')->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv6-safe length
            $table->string('device', 60)->nullable();     // Mobile / Tablet / Desktop
            $table->string('os', 60)->nullable();
            $table->string('browser', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'logged_in_at'], 'login_logs_user_time_idx');
            $table->index(['logged_in_at'], 'login_logs_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
