<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashback amounts need to support decimals (e.g. 0.50). The column was
     * added as an integer; widen it to decimal(10,2) to match scratch_cards and
     * the wallet columns (both already decimal(10,2)).
     */
    public function up(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('referral_scratch_levels', function (Blueprint $table) {
            $table->integer('amount')->default(0)->change();
        });
    }
};
