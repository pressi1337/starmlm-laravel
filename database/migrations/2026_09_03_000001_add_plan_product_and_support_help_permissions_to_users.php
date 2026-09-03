<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seventh and eighth sub-admin permissions: Plan Product and
     * Support & Help.
     *
     * Plan Product previously rode along with can_pin_requests, and
     * Support & Help was super-admin only. Both now have their own flag so
     * each can be granted independently — a sub-admin can run product
     * fulfilment without touching the pin lifecycle, and answer support
     * questions without either.
     *
     * Default 0, like every other can_* flag: the super-admin grants them
     * explicitly. NOTE this means sub-admins who could reach Plan Product via
     * their pin-requests permission LOSE it until granted — see the backfill
     * in phase3sqlchanges.txt if you want to preserve their current access.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_plan_product')->default(false)->after('can_promotion_logs');
            $table->boolean('can_support_help')->default(false)->after('can_plan_product');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_plan_product', 'can_support_help']);
        });
    }
};
