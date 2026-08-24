<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic key/value settings store.
     *
     * Built for the "show / hide this menu for users" toggles so each new one
     * costs a key rather than a new table + endpoint. First use is the
     * Company Docs menu (AppSetting::MENU_KEYS).
     *
     * `key` is a reserved word in MySQL, hence setting_key / setting_value.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->text('setting_value')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
