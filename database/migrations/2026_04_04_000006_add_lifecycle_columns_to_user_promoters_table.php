<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_promoters', function (Blueprint $table) {
            $table->timestamp('term_raised_at')->nullable()->after('pin_generated_at');
            $table->timestamp('terms_accepted_at')->nullable()->after('term_raised_at');
            $table->timestamp('auto_deleted_at')->nullable()->after('terms_accepted_at');
            $table->string('deleted_reason')->nullable()->after('auto_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_promoters', function (Blueprint $table) {
            $table->dropColumn(['term_raised_at', 'terms_accepted_at', 'auto_deleted_at', 'deleted_reason']);
        });
    }
};
