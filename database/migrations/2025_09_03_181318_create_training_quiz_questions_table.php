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
        Schema::create('training_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->integer('training_quiz_id')->nullable();
            $table->tinyInteger('lang_type')->default(1); // 1-english, 2-tamil
            $table->text('question')->nullable();
            $table->integer('time_limit')->default(30); // seconds
                // earnings per promotor level
            $table->integer('promotor')->default(10);
            $table->integer('promotor1')->default(15);
            $table->integer('promotor2')->default(20);
            $table->integer('promotor3')->default(25);
            $table->integer('promotor4')->default(30);
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
        Schema::dropIfExists('training_quiz_questions');
    }
};
