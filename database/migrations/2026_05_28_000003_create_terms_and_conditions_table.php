<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Terms & Conditions document. Single-row pattern (upsert on the latest
     * non-deleted row). Stored as LONGTEXT so the admin can paste arbitrarily
     * long content — plain text with newlines or raw HTML, both supported by
     * the PWA renderer.
     */
    public function up(): void
    {
        Schema::create('terms_and_conditions', function (Blueprint $table) {
            $table->id();
            $table->longText('content')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_and_conditions');
    }
};
