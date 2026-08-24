<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company documents shown to users under "Company Docs".
     *
     * Admin uploads an image (any format) or a PDF with a title, and can
     * show/hide, edit or delete it. Users see only active rows and can view
     * them inside the app — the UI intentionally offers no download action.
     *
     * file_path holds the stored filename from the chunked upload endpoint
     * (same convention as video_path), served from /storage/uploads/final/.
     */
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path');
            // 1 = image, 2 = pdf (see CompanyDocument::FILE_TYPE_*)
            $table->tinyInteger('file_type')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            // Show / hide toggle for the user-facing list.
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_deleted'], 'company_docs_visible_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
