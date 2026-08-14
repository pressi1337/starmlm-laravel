<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the missing indexes on the hot, fast-growing tables.
     *
     * Almost none of the core business tables had any index beyond the primary
     * key, so every user-facing query was a full table scan. That is invisible
     * on a small database and gets progressively worse as rows accumulate —
     * which is exactly how "the app got slow recently" presents.
     *
     * Each index is created individually and guarded, so a table/column that
     * doesn't exist (or an index already added by hand) can never abort the
     * whole migration.
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
     * used by the app, so the index can serve the whole lookup.
     */
    private function indexes(): array
    {
        return [
            // Hit on EVERY quiz submission (referral bonus) and on the
            // dashboard's referral counts.
            ['users', ['referred_by'], 'idx_users_referred_by'],
            // Login / duplicate checks.
            ['users', ['mobile'], 'idx_users_mobile'],
            // Admin user lists and role filtering.
            ['users', ['role', 'is_deleted'], 'idx_users_role_deleted'],

            // Biggest table in the system: ~8 rows per user per day.
            ['earning_histories', ['user_id', 'is_deleted'], 'idx_eh_user_deleted'],
            ['earning_histories', ['earning_date'], 'idx_eh_date'],

            // Looked up on every promotion-video fetch and quiz submit.
            ['user_promoter_sessions', ['user_id', 'attend_at', 'session_type'], 'idx_ups_user_date_type'],

            // Resolved on every video fetch / pin screen.
            ['user_promoters', ['user_id', 'status', 'is_deleted'], 'idx_up_user_status'],

            ['scratch_cards', ['user_id', 'is_deleted'], 'idx_sc_user_deleted'],

            ['withdraw_requests', ['user_id', 'is_deleted'], 'idx_wr_user_deleted'],
            ['withdraw_requests', ['status'], 'idx_wr_status'],
            ['withdraw_requests', ['request_at'], 'idx_wr_request_at'],

            ['user_referrals', ['parent_id'], 'idx_ur_parent'],
            ['user_referrals', ['child_id'], 'idx_ur_child'],

            ['user_training_videos', ['user_id', 'status'], 'idx_utv_user_status'],
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
