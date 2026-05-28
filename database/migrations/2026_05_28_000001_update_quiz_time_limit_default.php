<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bump the per-question quiz timer from 30s to 55s.
     *  • Changes the column DEFAULT on both quiz-question tables.
     *  • Lifts any existing rows that still carry the old default of 30 to 55
     *    so the timer change is visible immediately. Custom values (anything
     *    other than 30) are left alone — assumed admin-set intentionally.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE promotion_quiz_questions ALTER COLUMN time_limit SET DEFAULT 55');
        DB::statement('ALTER TABLE training_quiz_questions  ALTER COLUMN time_limit SET DEFAULT 55');

        DB::table('promotion_quiz_questions')->where('time_limit', 30)->update(['time_limit' => 55]);
        DB::table('training_quiz_questions')->where('time_limit', 30)->update(['time_limit' => 55]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotion_quiz_questions ALTER COLUMN time_limit SET DEFAULT 30');
        DB::statement('ALTER TABLE training_quiz_questions  ALTER COLUMN time_limit SET DEFAULT 30');

        DB::table('promotion_quiz_questions')->where('time_limit', 55)->update(['time_limit' => 30]);
        DB::table('training_quiz_questions')->where('time_limit', 55)->update(['time_limit' => 30]);
    }
};
