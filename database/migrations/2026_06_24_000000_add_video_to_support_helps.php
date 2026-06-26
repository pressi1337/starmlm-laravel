<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional video for a Support & Help Q&A item. Stores the uploaded
     * filename (the chunked-upload `stored_filename`, same convention as
     * daily/promotion videos' video_path). Nullable — most entries are
     * text-only. When present, the PWA renders an unrestricted player.
     */
    public function up(): void
    {
        Schema::table('support_helps', function (Blueprint $table) {
            $table->string('video')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('support_helps', function (Blueprint $table) {
            $table->dropColumn('video');
        });
    }
};
