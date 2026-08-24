<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company personal (internal) documents — admin-side only, never shown to
     * end users. Distinct from `company_documents`, which is the user-facing
     * "Company Docs" list.
     *
     * Sub-admins holding the `personal_documents` permission can add entries;
     * anything they add starts INACTIVE and only a super-admin can activate it.
     * Documents added by a super-admin are active immediately.
     *
     * file_type: 1 = image, 2 = pdf, 3 = excel (see the model constants).
     */
    public function up(): void
    {
        Schema::create('company_personal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path');
            $table->tinyInteger('file_type')->default(1);
            // Show / hide. Defaults to 0 so a sub-admin's upload waits for the
            // super-admin to activate it; the controller sets 1 for super-admin.
            $table->tinyInteger('is_active')->default(0);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            // Who last flipped active/inactive, and when.
            $table->unsignedBigInteger('status_changed_by')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_deleted'], 'cpd_status_idx');
            $table->index(['created_by'], 'cpd_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_personal_documents');
    }
};
