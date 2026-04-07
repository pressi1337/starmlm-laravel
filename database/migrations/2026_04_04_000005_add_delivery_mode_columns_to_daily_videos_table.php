<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->tinyInteger('delivery_mode')->default(1)->after('type');
            $table->integer('priority')->default(0)->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('daily_videos', function (Blueprint $table) {
            $table->dropColumn(['delivery_mode', 'priority']);
        });
    }
};
