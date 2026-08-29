<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal documents become SETS: one record (title / remark / visibility)
     * holding many files — e.g. a "GST Bills" set with 10 PDFs.
     *
     * Each file keeps its ORIGINAL upload name alongside the stored name. The
     * uploader rewrites files to "{uuid}_{original}" for safe unique storage,
     * so without this column the real name is only recoverable by string
     * surgery — and that breaks on any filename containing an underscore.
     *
     * Existing single-file documents are migrated into one child row each, so
     * nothing is lost and the UI has a single code path.
     */
    public function up(): void
    {
        Schema::create('company_personal_document_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('file_path');
            // The name the user actually uploaded — what downloads are named.
            $table->string('original_name', 255)->nullable();
            $table->tinyInteger('file_type')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'is_deleted'], 'cpdf_doc_idx');
            $table->foreign('document_id')
                ->references('id')->on('company_personal_documents')
                ->onDelete('cascade');
        });

        // The parent no longer needs its own file; sets carry files instead.
        // Kept (nullable) rather than dropped so a rollback loses nothing.
        Schema::table('company_personal_documents', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
        });

        $this->backfillExistingDocuments();
    }

    /**
     * Move every existing document's single file into the new child table,
     * recovering the original name from the "{uuid}_{original}" stored name.
     */
    private function backfillExistingDocuments(): void
    {
        $rows = DB::table('company_personal_documents')
            ->select('id', 'file_path', 'file_type', 'created_at', 'updated_at')
            ->whereNotNull('file_path')
            ->where('file_path', '<>', '')
            ->orderBy('id')
            ->get();

        $now = now();
        foreach ($rows as $row) {
            $exists = DB::table('company_personal_document_files')
                ->where('document_id', $row->id)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('company_personal_document_files')->insert([
                'document_id'   => $row->id,
                'file_path'     => $row->file_path,
                'original_name' => $this->stripUploadPrefix($row->file_path),
                'file_type'     => $row->file_type ?? 1,
                'sort_order'    => 0,
                'is_deleted'    => 0,
                'created_at'    => $row->created_at ?? $now,
                'updated_at'    => $row->updated_at ?? $now,
            ]);
        }
    }

    /**
     * "3f1c…-a9_GST_March.pdf" -> "GST_March.pdf".
     * Only a genuine UUID prefix is stripped, so a filename that merely
     * contains underscores survives intact.
     */
    private function stripUploadPrefix(?string $stored): string
    {
        $stored = (string) $stored;
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_/i';
        $name = preg_replace($pattern, '', $stored, 1);

        return $name !== '' ? $name : $stored;
    }

    public function down(): void
    {
        Schema::dropIfExists('company_personal_document_files');
    }
};
