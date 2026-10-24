<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second pass of indexes, covering what 2026_08_14_000000 left out.
     *
     * That migration indexed the biggest tables. This one covers the rest:
     * the two lookups every login and registration performs, the quiz tables
     * (which are read on every quiz load and had no index on their foreign
     * keys at all), the per-user-per-day watch/attendance tables, and the
     * invoice query behind the Tax report.
     *
     * Every entry here was checked against the actual query in the code, and
     * checked against the indexes that already exist, so nothing is duplicated.
     * Small configuration tables (FAQs, terms, youtube channels, bank details,
     * scratch levels) are deliberately NOT indexed: they hold a handful of
     * rows, MySQL scans them regardless, and an unused index only costs write
     * time.
     *
     * Each index is created individually and guarded, so a missing table or
     * column — or one already added by hand — can never abort the rest.
     *
     * NOTE: adding an index rebuilds the table. InnoDB does this online, but on
     * a large table it still takes time and IO — run this off-peak.
     */
    public function up(): void
    {
        foreach ($this->indexes() as [$table, $columns, $name]) {
            $this->addIndex($table, $columns, $name);
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as [$table, $columns, $name]) {
            try {
                if (Schema::hasTable($table)) {
                    Schema::table($table, function ($t) use ($name) {
                        $t->dropIndex($name);
                    });
                }
            } catch (\Throwable $e) {
                // Index may not exist — nothing to undo.
            }
        }
    }

    /**
     * [table, [columns], index name] — columns mirror the actual WHERE clauses
     * used by the app, in the order that lets the index serve the whole lookup.
     */
    private function indexes(): array
    {
        return [
            // ---- users -------------------------------------------------
            // The login lookup: where('username', $request->username).
            // NOTE: the admin search uses username LIKE '%term%', which no
            // B-tree index can serve — this is for the exact-match login only.
            ['users', ['username'], 'idx_users_username'],
            // Resolving the referrer on every registration.
            ['users', ['referral_code'], 'idx_users_referral_code'],
            // "Live rows" — the pair filtered together throughout the app.
            ['users', ['is_deleted', 'is_active'], 'idx_users_deleted_active'],
            // Admin lists filtered by promoter level.
            ['users', ['is_deleted', 'current_promoter_level'], 'idx_users_deleted_level'],

            // ---- per-user-per-day tables (these grow fastest) ----------
            // Read on every daily-video screen and every watch submission.
            ['daily_video_watch_details', ['user_id', 'daily_video_id', 'watched_date'], 'idx_dvwd_user_video_date'],
            ['training_attendances', ['user_id', 'training_video_id'], 'idx_ta_user_video'],

            // ---- quiz tables: foreign keys, read on every quiz load ----
            // None of these had any index, so each quiz load scanned the
            // whole table once per level of nesting.
            ['training_video_quizzes', ['training_video_id'], 'idx_tvq_video'],
            ['training_quiz_questions', ['training_quiz_id'], 'idx_tqq_quiz'],
            ['training_quiz_choices', ['training_quiz_question_id'], 'idx_tqc_question'],
            ['promotion_video_quizzes', ['promotion_video_id'], 'idx_pvq_video'],
            ['promotion_quiz_questions', ['promotion_video_quiz_id'], 'idx_pqq_quiz'],
            ['promotion_quiz_choices', ['promotion_quiz_question_id'], 'idx_pqc_question'],

            // ---- content lookups --------------------------------------
            // "Today's video" and "the video for day N".
            ['daily_videos', ['is_deleted', 'is_active', 'showing_date'], 'idx_dv_visible_date'],
            ['training_videos', ['is_deleted', 'is_active', 'day'], 'idx_tv_visible_day'],

            // ---- invoices / Tax report --------------------------------
            // where is_deleted=0 and status=DELIVERED, filtered and ordered by
            // delivered_at. The existing pbr indexes are (user_id, level) and
            // (status), neither of which serves this.
            ['promoter_box_requests', ['is_deleted', 'status', 'delivered_at'], 'idx_pbr_live_status_delivered'],

            // ---- bank details -----------------------------------------
            // One row per user, fetched by user_id on the profile screen.
            ['user_bank_details', ['user_id'], 'idx_ubd_user'],
        ];
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        try {
            if (!Schema::hasTable($table)) {
                return;
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    return;
                }
            }
            if ($this->indexExists($table, $name)) {
                return;
            }
            Schema::table($table, function ($t) use ($columns, $name) {
                $t->index($columns, $name);
            });
        } catch (\Throwable $e) {
            // Never let one index abort the rest.
            Log::warning('Could not add index', [
                'table' => $table,
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $name)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
};
