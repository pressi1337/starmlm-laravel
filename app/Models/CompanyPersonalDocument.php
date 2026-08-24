<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal company document (image / PDF / Excel). Admin-side only — never
 * exposed to end users. See the create migration for the workflow.
 */
class CompanyPersonalDocument extends Model
{
    const FILE_TYPE_IMAGE = 1;
    const FILE_TYPE_PDF   = 2;
    const FILE_TYPE_EXCEL = 3;

    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif', 'tiff', 'tif'];
    const PDF_EXTENSIONS   = ['pdf'];
    const EXCEL_EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'csv'];

    protected $fillable = [
        'title',
        'file_path',
        'file_type',
        'is_active',
        'remark',
        'created_by',
        'updated_by',
        'status_changed_by',
        'status_changed_at',
        'is_deleted',
    ];

    protected $casts = [
        'status_changed_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusChanger()
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /** Derive the stored type from a filename. Defaults to image. */
    public static function detectFileType(?string $filename): int
    {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        if (in_array($ext, self::PDF_EXTENSIONS, true)) {
            return self::FILE_TYPE_PDF;
        }
        if (in_array($ext, self::EXCEL_EXTENSIONS, true)) {
            return self::FILE_TYPE_EXCEL;
        }
        return self::FILE_TYPE_IMAGE;
    }

    /** True if the filename is an accepted image, PDF or Excel file. */
    public static function isAllowedFile(?string $filename): bool
    {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return in_array($ext, array_merge(
            self::IMAGE_EXTENSIONS,
            self::PDF_EXTENSIONS,
            self::EXCEL_EXTENSIONS
        ), true);
    }

    public function typeLabel(): string
    {
        return [
            self::FILE_TYPE_IMAGE => 'Image',
            self::FILE_TYPE_PDF   => 'PDF',
            self::FILE_TYPE_EXCEL => 'Excel',
        ][(int) $this->file_type] ?? 'File';
    }

    public function statusLabel(): string
    {
        return (int) $this->is_active === 1 ? 'Active' : 'Inactive';
    }
}
