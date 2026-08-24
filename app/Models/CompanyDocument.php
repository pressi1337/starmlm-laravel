<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company document (image or PDF) surfaced to users under "Company Docs".
 */
class CompanyDocument extends Model
{
    const FILE_TYPE_IMAGE = 1;
    const FILE_TYPE_PDF   = 2;

    /** Extensions accepted for each type. */
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif', 'tiff', 'tif'];
    const PDF_EXTENSIONS   = ['pdf'];

    protected $fillable = [
        'title',
        'file_path',
        'file_type',
        'created_by',
        'updated_by',
        'is_active',
        'is_deleted',
    ];

    /** Derive the stored type from a filename, defaulting to image. */
    public static function detectFileType(?string $filename): int
    {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return in_array($ext, self::PDF_EXTENSIONS, true)
            ? self::FILE_TYPE_PDF
            : self::FILE_TYPE_IMAGE;
    }

    /** True if the filename is an accepted image or PDF. */
    public static function isAllowedFile(?string $filename): bool
    {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return in_array($ext, array_merge(self::IMAGE_EXTENSIONS, self::PDF_EXTENSIONS), true);
    }

    public function typeLabel(): string
    {
        return (int) $this->file_type === self::FILE_TYPE_PDF ? 'PDF' : 'Image';
    }
}
