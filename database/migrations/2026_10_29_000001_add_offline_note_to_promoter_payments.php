<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offline plan payments.
     *
     * A promoter who pays in cash or by direct transfer still needs the
     * request marked paid before a pin can be issued, so an admin records the
     * payment by hand. Such a row carries settled_via = 'offline', the
     * admin's reference in transaction_id, and their note here — kept apart
     * from error_desc so an offline note is never confused with a gateway
     * error message.
     */
    public function up(): void
    {
        Schema::table('promoter_payments', function (Blueprint $table) {
            $table->string('offline_note', 255)->nullable()->after('settled_via');
        });
    }

    public function down(): void
    {
        Schema::table('promoter_payments', function (Blueprint $table) {
            $table->dropColumn('offline_note');
        });
    }
};
