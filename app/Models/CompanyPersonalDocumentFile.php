<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One file inside a personal-document SET. The parent
 * (CompanyPersonalDocument) carries the title, remark and visibility; the
 * files live here so a single record can hold, say, ten GST bills.
 */
class CompanyPersonalDocumentFile extends Model
{
    protected $table = 'company_personal_document_files';

    protected $fillable = [
        'document_id',
        'file_path',
        'original_name',
        'file_type',
        'sort_order',
        'is_deleted',
    ];

    public function document()
    {
        return $this->belongsTo(CompanyPersonalDocument::class, 'document_id');
    }

    /**
     * "3f1c…-a9_GST_March.pdf" -> "GST_March.pdf".
     *
     * The uploader stores files as "{uuid}_{original}". Only a genuine UUID
     * prefix is stripped, so a name that merely contains underscores survives
     * intact — the reason we don't just split on "_".
     */
    public static function stripUploadPrefix(?string $stored): string
    {
        $stored = (string) $stored;
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_/i';
        $name = preg_replace($pattern, '', $stored, 1);

        return $name !== '' && $name !== null ? $name : $stored;
    }

    /**
     * The name a download should carry. Prefers the stored original; falls
     * back to deriving it for rows written before that column existed.
     */
    public function downloadName(): string
    {
        if (is_string($this->original_name) && trim($this->original_name) !== '') {
            return trim($this->original_name);
        }

        return self::stripUploadPrefix($this->file_path);
    }

    public function typeLabel(): string
    {
        // Single source of truth on the parent — the label list grows there.
        return CompanyPersonalDocument::labelForType($this->file_type);
    }

    /**
     * Where private copies live. Deliberately OUTSIDE storage/app/public, the
     * only directory the `public/storage` symlink exposes, so these files are
     * not reachable over the web at all — internal documents must not be
     * fetchable by anyone who happens to know the URL.
     */
    public const PRIVATE_DIR = 'app/personal-documents';

    /**
     * The stored name, pinned to a bare filename. Everything that builds a
     * path goes through here so a value like "../../.env" can never escape
     * the intended directory.
     */
    public function safeStoredName(): string
    {
        return basename(str_replace('\\', '/', (string) $this->file_path));
    }

    /**
     * Locate a stored file by name, private location first, then the public
     * upload dir. Static so validation can check a file that has been
     * uploaded but not yet attached to a document row.
     */
    public static function resolveStoredPath(?string $storedName): ?string
    {
        $name = basename(str_replace(chr(92), '/', (string) $storedName));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        foreach ([
            storage_path(self::PRIVATE_DIR . '/' . $name),
            storage_path('app/public/uploads/final/' . $name),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Path a privatised copy would occupy (whether or not it exists yet). */
    public function privatePath(): string
    {
        return storage_path(self::PRIVATE_DIR . '/' . $this->safeStoredName());
    }

    /** Legacy location: the web-servable upload dir shared with videos. */
    public function publicPath(): string
    {
        return storage_path('app/public/uploads/final/' . $this->safeStoredName());
    }

    /** True once this file has been moved out of the web-servable directory. */
    public function isPrivate(): bool
    {
        return is_file($this->privatePath());
    }

    /**
     * Absolute path on disk, or null when the file is missing.
     *
     * Checks the private location first and falls back to the old public one,
     * so documents uploaded before privatisation keep working untouched — the
     * backfill command can run whenever, and nothing breaks if it never does.
     */
    public function absolutePath(): ?string
    {
        $private = $this->privatePath();
        if (is_file($private)) {
            return $private;
        }

        $public = $this->publicPath();

        return is_file($public) ? $public : null;
    }
}
