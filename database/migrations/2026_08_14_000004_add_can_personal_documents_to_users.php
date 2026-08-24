<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fifth sub-admin permission: Company Personal Documents.
     * Defaults to 0 for everyone — the super-admin must grant it explicitly,
     * matching how the other can_* flags behave.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_personal_documents')->default(false)->after('can_suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_personal_documents');
        });
    }
};
