<?php

use App\Models\ReferralScratchLevel;
use App\Models\ReferralScratchRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire the range concept: each scratch config row (promotor_level, level)
     * now carries a single cashback amount + message instead of multiple
     * From/To ranges. Existing data is seeded from each level's first range so
     * nothing is lost; the referral_scratch_ranges table is left in place but is
     * no longer used.
     */
    public function up(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->integer('amount')->default(0)->after('level');
            $table->string('msg')->nullable()->after('amount');
        });

        foreach (ReferralScratchLevel::where('is_deleted', 0)->get() as $lvl) {
            $firstRange = ReferralScratchRange::where('referral_scratch_level_id', $lvl->id)
                ->where('is_deleted', 0)
                ->orderBy('id')
                ->first();
            if ($firstRange) {
                $lvl->amount = (int) $firstRange->amount;
                $lvl->msg = $firstRange->msg;
                $lvl->save();
            }
        }
    }

    public function down(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->dropColumn(['amount', 'msg']);
        });
    }
};
