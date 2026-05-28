<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-submitted suggestions. Each row = one suggestion.
     *
     * Lifecycle:
     *   • User creates while is_read=0 (max 3 unread per user enforced server-side).
     *   • User can edit/soft-delete while is_read=0.
     *   • Admin flips is_read=1 → user loses edit/delete; row stays visible
     *     to the user as "Seen". Once marked read, the slot frees up so the
     *     user can submit another.
     */
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->text('content');
            $table->tinyInteger('is_read')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->integer('read_by')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'is_deleted'], 'suggestions_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
