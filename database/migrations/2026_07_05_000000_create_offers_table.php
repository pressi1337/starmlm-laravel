<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offer module master config. Single-row pattern (admin upserts the latest
     * non-deleted row, same as terms_and_conditions):
     *   • is_active      — offer menu only appears for users when this is 1.
     *   • start_at       — datetime the offer goes live. Before it, the PWA
     *                      shows a countdown (hh:mm:ss); points are not awarded.
     *   • top_list_count — how many rows the "Top Points" leaderboard shows
     *                      (admin-configurable, defaults to 10).
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->dateTime('start_at')->nullable();
            $table->integer('top_list_count')->default(10);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
