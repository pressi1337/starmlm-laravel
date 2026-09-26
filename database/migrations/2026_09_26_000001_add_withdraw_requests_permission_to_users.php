<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenth sub-admin permission: Withdraw Request.
     *
     * Withdrawals were super-admin only. The flag grants full access to the
     * menu — list, both exports, the single status update and the bulk Excel
     * import.
     *
     * Worth being clear about what that includes: setting a withdrawal to
     * Rejected returns the amount to the user's wallet. A sub-admin with this
     * flag can move money, exactly as a super-admin can.
     *
     * Default 0, like every other can_* flag. No sub-admin loses anything,
     * because none could reach this screen before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_withdraw_requests')->default(false)->after('can_promotion_settings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_withdraw_requests');
        });
    }
};
