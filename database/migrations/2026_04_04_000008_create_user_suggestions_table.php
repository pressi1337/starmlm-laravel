<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('suggestion_text');
            $table->text('admin_response_text')->nullable();
            $table->string('admin_reaction_emoji', 20)->nullable();
            $table->timestamp('admin_reacted_at')->nullable();
            $table->unsignedBigInteger('admin_reacted_by')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status', 'is_deleted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_suggestions');
    }
};
