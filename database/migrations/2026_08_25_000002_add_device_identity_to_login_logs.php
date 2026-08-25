<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tell devices apart, not just networks.
     *
     * IP only identifies a connection — a whole household or an entire mobile
     * carrier (CGNAT) can share one, so "10 accounts on one IP" proves nothing
     * on its own. These two columns answer "same phone or different phone?":
     *
     *   device_id    — a random id the app generates once and keeps in the
     *                  browser's storage. The SAME id appearing under several
     *                  accounts is strong evidence of one person running them.
     *                  Cleared if the user wipes app data / reinstalls, so a
     *                  missing or changed id is not proof of anything.
     *   device_model — pulled out of the user agent (e.g. "SM-G991B"). Weaker
     *                  on its own (many people own the same phone) but useful
     *                  next to device_id and works even when device_id is new.
     *   screen       — viewport size, a cheap extra discriminator.
     */
    public function up(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->after('browser');
            $table->string('device_model', 100)->nullable()->after('device_id');
            $table->string('screen', 30)->nullable()->after('device_model');

            $table->index('device_id', 'login_logs_device_idx');
        });
    }

    public function down(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->dropIndex('login_logs_device_idx');
            $table->dropColumn(['device_id', 'device_model', 'screen']);
        });
    }
};
