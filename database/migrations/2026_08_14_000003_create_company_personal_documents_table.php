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
     * is_sub_admin_visible controls whether SUB-ADMINS may see the document.
     * The super-admin always sees everything and owns this flag. A sub-admin
     * sees a document only when it is visible, or when they uploaded it
     * themselves (so they can track their own submissions).
     *
     * Sub-admin uploads default to hidden; a super-admin's own upload is
     * visible immediately.
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
            // Deliberately NOT called is_active: this is an access flag, not
            // the usual show/hide. 0 = hidden from sub-admins (the default).
            $table->tinyInteger('is_sub_admin_visible')->default(0);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            // Who last changed visibility, and when.
            $table->unsignedBigInteger('status_changed_by')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['is_sub_admin_visible', 'is_deleted'], 'cpd_visible_idx');
            $table->index(['created_by'], 'cpd_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_personal_documents');
    }
};
