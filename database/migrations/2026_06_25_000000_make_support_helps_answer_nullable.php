<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Answer is now optional (a Support & Help item can be answer-only,
     * video-only, or both). Make the column nullable so a video-only entry
     * stores NULL instead of an empty string.
     */
    public function up(): void
    {
        Schema::table('support_helps', function (Blueprint $table) {
            $table->text('answer')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('support_helps', function (Blueprint $table) {
            $table->text('answer')->nullable(false)->change();
        });
    }
};
