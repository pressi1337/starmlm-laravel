<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support & Help Q&A items. One row per question. Admin manages CRUD;
     * users see active rows ordered by id ASC (insertion order) rendered as
     * an accordion under the new "Support & Help" PWA menu.
     */
    public function up(): void
    {
        Schema::create('support_helps', function (Blueprint $table) {
            $table->id();
            // Question is TEXT (not VARCHAR) so long-form questions are fine.
            $table->text('question');
            $table->text('answer');
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_helps');
    }
};
