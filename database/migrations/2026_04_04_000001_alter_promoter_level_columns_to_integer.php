<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('current_promoter_level')->nullable()->change();
        });

        Schema::table('user_promoters', function (Blueprint $table) {
            $table->integer('level')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('current_promoter_level')->nullable()->change();
        });

        Schema::table('user_promoters', function (Blueprint $table) {
            $table->tinyInteger('level')->nullable()->change();
        });
    }
};
