<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sixth sub-admin permission: Promotion Log (read-only audit of promotion
     * quiz attempts). Previously the log rode along with can_promotion_videos;
     * it now has its own flag so log access can be granted without granting
     * promotion-video editing, and vice versa.
     *
     * Defaults to 0 for everyone — the super-admin must grant it explicitly,
     * matching how the other can_* flags behave. Existing sub-admins who had
     * promotion-video access therefore LOSE the log until it is granted; see
     * the optional backfill in phase3sqlchanges.txt if you want to preserve it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_promotion_logs')->default(false)->after('can_personal_documents');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_promotion_logs');
        });
    }
};
