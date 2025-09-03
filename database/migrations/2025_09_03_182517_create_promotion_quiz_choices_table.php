<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promotion_quiz_choices', function (Blueprint $table) {
            $table->id();
            $table->integer('promotion_quiz_question_id')->nullable();
            $table->tinyInteger('lang_type')->default(1); // 1-english, 2-tamil
            $table->string('choice_value')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_quiz_choices');
    }
};
