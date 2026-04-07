<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earning_histories', function (Blueprint $table) {
            $table->integer('source_user_id')->nullable()->after('user_id');
            $table->integer('referral_depth')->nullable()->after('earning_type');
            $table->integer('beneficiary_promoter_level')->nullable()->after('referral_depth');
            $table->tinyInteger('trigger_type')->nullable()->after('beneficiary_promoter_level');
            $table->integer('income_rule_id')->nullable()->after('trigger_type');
            $table->integer('reference_id')->nullable()->after('income_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('earning_histories', function (Blueprint $table) {
            $table->dropColumn([
                'source_user_id',
                'referral_depth',
                'beneficiary_promoter_level',
                'trigger_type',
                'income_rule_id',
                'reference_id',
            ]);
        });
    }
};
