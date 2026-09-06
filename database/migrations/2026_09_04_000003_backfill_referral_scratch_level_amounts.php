<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the existing single cashback amounts into the pool table, so
     * referral_scratch_level_amounts is the one place payouts are read from.
     *
     * Before this, a pair configured prior to pools had no pool rows and fell
     * back to referral_scratch_levels.amount at payout time. That fallback
     * still exists as a safety net, but after this backfill nothing should be
     * relying on it: every configured pair owns a pool entry.
     *
     * Idempotent — a pair that already has live pool rows is skipped, so
     * re-running this cannot duplicate amounts.
     */
    public function up(): void
    {
        if (!Schema::hasTable('referral_scratch_level_amounts')
            || !Schema::hasTable('referral_scratch_levels')) {
            return;
        }

        $now = now();

        // Only pairs that actually pay something and have no pool yet.
        $levels = DB::table('referral_scratch_levels')
            ->where('is_deleted', 0)
            ->where('amount', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('referral_scratch_level_amounts')
                    ->whereColumn('referral_scratch_level_amounts.referral_scratch_level_id',
                                  'referral_scratch_levels.id')
                    ->where('referral_scratch_level_amounts.is_deleted', 0);
            })
            ->get();

        $rows = [];
        foreach ($levels as $level) {
            $rows[] = [
                'referral_scratch_level_id' => $level->id,
                'amount' => $level->amount,
                'msg' => $level->msg,
                'order_no' => 0,
                'created_by' => $level->created_by ?? null,
                'updated_by' => $level->updated_by ?? null,
                'is_active' => 1,
                'is_deleted' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('referral_scratch_level_amounts')->insert($chunk);
        }
    }

    /**
     * Remove only what this backfill created: a pair's single pool entry that
     * still matches the pair's own amount. Pools the admin has since edited or
     * extended are left alone — reversing this must not throw away real work.
     */
    public function down(): void
    {
        if (!Schema::hasTable('referral_scratch_level_amounts')) {
            return;
        }

        $ids = DB::table('referral_scratch_level_amounts as a')
            ->join('referral_scratch_levels as l', 'l.id', '=', 'a.referral_scratch_level_id')
            ->where('a.is_deleted', 0)
            ->whereColumn('a.amount', 'l.amount')
            ->whereRaw('(SELECT COUNT(*) FROM referral_scratch_level_amounts x
                          WHERE x.referral_scratch_level_id = a.referral_scratch_level_id
                            AND x.is_deleted = 0) = 1')
            ->pluck('a.id');

        if ($ids->isNotEmpty()) {
            DB::table('referral_scratch_level_amounts')->whereIn('id', $ids)->delete();
        }
    }
};
