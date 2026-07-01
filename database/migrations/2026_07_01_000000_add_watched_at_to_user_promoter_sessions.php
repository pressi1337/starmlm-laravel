<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when each set's promotion video was actually watched, set by the
     * server-authoritative markVideoWatched endpoint. Feeds the "Watched At"
     * column of the promotion quiz audit log. Reset naturally on retry (the
     * set goes back to ASSIGNED and the next watch overwrites the timestamp).
     */
    public function up(): void
    {
        Schema::table('user_promoter_sessions', function (Blueprint $table) {
            $table->timestamp('set1_watched_at')->nullable()->after('set1_status');
            $table->timestamp('set2_watched_at')->nullable()->after('set2_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_promoter_sessions', function (Blueprint $table) {
            $table->dropColumn(['set1_watched_at', 'set2_watched_at']);
        });
    }
};
