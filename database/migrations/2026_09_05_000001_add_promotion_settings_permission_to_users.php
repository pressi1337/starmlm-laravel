<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ninth sub-admin permission: Promotion Settings.
     *
     * The Promotion Settings screen (scratch cashback amounts per promoter and
     * referral level) was super-admin only. It now has its own flag so it can
     * be granted independently, with full access to the menu — read, edit the
     * amount pools, toggle a pair active, and delete one.
     *
     * Default 0, like every other can_* flag: the super-admin grants it
     * explicitly. No sub-admin loses anything, because none could reach this
     * screen before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_promotion_settings')->default(false)->after('can_support_help');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_promotion_settings');
        });
    }
};
