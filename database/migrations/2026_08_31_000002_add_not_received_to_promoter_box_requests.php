<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user can now report that a dispatched batch never arrived, which puts
     * it in status 4 (Not Received) for the admin to chase.
     *
     * Re-sending the batch clears the timestamp along with the status, so a
     * row that has been dispatched again reads as cleanly Sent rather than
     * carrying an old complaint.
     */
    public function up(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            $table->timestamp('not_received_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('promoter_box_requests', function (Blueprint $table) {
            $table->dropColumn('not_received_at');
        });
    }
};
