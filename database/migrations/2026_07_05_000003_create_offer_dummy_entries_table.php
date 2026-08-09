<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manually-entered leaderboard rows.
     *
     * These are NOT real users — they hold no user_id, earn nothing, and are
     * never part of any points calculation, payout, or the admin "User Points"
     * analytics. They exist only to be merged into the Top Points list for
     * display, ranked by their manually-set points alongside real users.
     *
     * Kept in a separate table (rather than fake rows in `users` or
     * `offer_points`) precisely so real earnings data can never be polluted by
     * them and they stay auditable as display-only.
     */
    public function up(): void
    {
        Schema::create('offer_dummy_entries', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('name')->nullable();
            $table->decimal('points', 10, 2)->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_deleted'], 'offer_dummy_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_dummy_entries');
    }
};
