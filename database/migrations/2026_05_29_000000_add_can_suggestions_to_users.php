<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fourth sub-admin permission: Suggestions inbox access.
     *
     * Defaults to 0 for everyone (including existing sub-admins) — the
     * suggestions admin page didn't exist for sub-admin before this column,
     * so they never had access; super-admin must explicitly grant it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_suggestions')->default(false)->after('can_pin_requests');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_suggestions');
        });
    }
};
