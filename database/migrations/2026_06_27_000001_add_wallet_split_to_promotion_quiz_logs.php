<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track how a confirmed quiz earning was split between the two wallets:
     * the bulk to the promotion wallet, a small % to the savings (grow) wallet.
     * Both stay NULL until the attempt is confirmed.
     */
    public function up(): void
    {
        Schema::table('promotion_quiz_logs', function (Blueprint $table) {
            $table->decimal('main_wallet_amount', 10, 2)->nullable()->after('earned_amount');
            $table->decimal('saving_amount', 10, 2)->nullable()->after('main_wallet_amount');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_quiz_logs', function (Blueprint $table) {
            $table->dropColumn(['main_wallet_amount', 'saving_amount']);
        });
    }
};
