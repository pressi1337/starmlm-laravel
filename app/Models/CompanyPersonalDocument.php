<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal company document. Admin-side only — never exposed to end users.
 * See the create migration for the workflow.
 *
 * Uploads are permissive by design: an internal archive should be able to
 * hold whatever the business needs — Word, video, zip, audio, CAD, anything.
 * So the rule is "allow unless denied" rather than a fixed whitelist, and
 * file_type exists purely to pick an icon and a label.
 */
class CompanyPersonalDocument extends Model
{
    // 1-3 predate the wider format support; their meaning is unchanged so
    // existing rows keep their icons without a migration.
    const FILE_TYPE_IMAGE   = 1;
    const FILE_TYPE_PDF     = 2;
    const FILE_TYPE_EXCEL   = 3;
    const FILE_TYPE_WORD    = 4;
    const FILE_TYPE_VIDEO   = 5;
    const FILE_TYPE_ARCHIVE = 6;
    const FILE_TYPE_AUDIO   = 7;
    const FILE_TYPE_SLIDES  = 8;
    const FILE_TYPE_TEXT    = 9;
    const FILE_TYPE_OTHER   = 10;

    /**
     * Largest single file, in bytes (2 GB).
     *
     * Enforced here on the server as well as in the browser: the picker's
     * check is a courtesy that anyone can bypass by calling the API directly,
     * and nothing else stops an upload filling the disk.
     */
    const MAX_FILE_BYTES = 2147483648;

    const IMAGE_EXTENSIONS   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif', 'tiff', 'tif', 'ico', 'avif'];
    const PDF_EXTENSIONS     = ['pdf'];
    const EXCEL_EXTENSIONS   = ['xls', 'xlsx', 'xlsm', 'xlsb', 'csv', 'ods'];
    const WORD_EXTENSIONS    = ['doc', 'docx', 'docm', 'odt', 'rtf'];
    const SLIDES_EXTENSIONS  = ['ppt', 'pptx', 'pptm', 'odp'];
    const VIDEO_EXTENSIONS   = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp', 'wmv', 'flv'];
    const AUDIO_EXTENSIONS   = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'amr'];
    const ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'];
    const TEXT_EXTENSIONS    = ['txt', 'log', 'md', 'json', 'xml', 'yml', 'yaml'];

    /**
     * The only extensions refused — everything else is accepted.
     *
     * These are the ones a web server may EXECUTE. The chunked uploader writes
     * into storage/app/public/uploads/final (reachable through the
     * public/storage symlink) and the file is only moved into private storage
     * once the document is saved, so a .php uploaded here would be executable
     * in that window — remote code execution, not just an unwanted file type.
     *
     * Note this is not about protecting the person downloading: .exe, .zip and
     * friends are allowed, because storing and handing back an installer is a
     * legitimate thing for an internal archive to do.
     */
    const BLOCKED_EXTENSIONS = [
        // PHP, in all the forms mod_php/FPM will happily run
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        'phps', 'phpt', 'pht', 'phtml', 'phar', 'inc',
        // Other server-side runtimes
        'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cer',
        'jsp', 'jspx', 'jsw', 'jsv', 'jspf',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        // Server configuration that changes how a directory is served
        'htaccess', 'htpasswd', 'user.ini',
    ];

    protected $fillable = [
        'title',
        'file_path',
        'file_type',
        'is_sub_admin_visible',
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

    /** The files that make up this set (a set may hold many). */
    public function files()
    {
        return $this->hasMany(CompanyPersonalDocumentFile::class, 'document_id')
            ->where('is_deleted', 0)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusChanger()
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /** Lower-cased extension of a filename, without the dot. */
    public static function extensionOf(?string $filename): string
    {
        return strtolower(trim(pathinfo((string) $filename, PATHINFO_EXTENSION)));
    }

    /**
     * Derive the stored type from a filename. Anything unrecognised is
     * FILE_TYPE_OTHER — it is still stored, it just gets a generic icon.
     */
    public static function detectFileType(?string $filename): int
    {
        $ext = self::extensionOf($filename);

        $map = [
            self::FILE_TYPE_IMAGE   => self::IMAGE_EXTENSIONS,
            self::FILE_TYPE_PDF     => self::PDF_EXTENSIONS,
            self::FILE_TYPE_EXCEL   => self::EXCEL_EXTENSIONS,
            self::FILE_TYPE_WORD    => self::WORD_EXTENSIONS,
            self::FILE_TYPE_SLIDES  => self::SLIDES_EXTENSIONS,
            self::FILE_TYPE_VIDEO   => self::VIDEO_EXTENSIONS,
            self::FILE_TYPE_AUDIO   => self::AUDIO_EXTENSIONS,
            self::FILE_TYPE_ARCHIVE => self::ARCHIVE_EXTENSIONS,
            self::FILE_TYPE_TEXT    => self::TEXT_EXTENSIONS,
        ];

        foreach ($map as $type => $extensions) {
            if (in_array($ext, $extensions, true)) {
                return $type;
            }
        }

        return self::FILE_TYPE_OTHER;
    }

    /**
     * Allow anything except the server-executable extensions above.
     *
     * A filename is checked on its FINAL extension, so "report.php.pdf" is a
     * PDF and "photo.jpg.php" is refused — which is the way round that matters,
     * since the last extension is what a web server dispatches on.
     */
    public static function isAllowedFile(?string $filename): bool
    {
        $name = trim((string) $filename);
        if ($name === '') {
            return false;
        }

        return !in_array(self::extensionOf($name), self::BLOCKED_EXTENSIONS, true);
    }

    public function typeLabel(): string
    {
        return self::labelForType($this->file_type);
    }

    public static function labelForType($fileType): string
    {
        return [
            self::FILE_TYPE_IMAGE   => 'Image',
            self::FILE_TYPE_PDF     => 'PDF',
            self::FILE_TYPE_EXCEL   => 'Excel',
            self::FILE_TYPE_WORD    => 'Word',
            self::FILE_TYPE_VIDEO   => 'Video',
            self::FILE_TYPE_ARCHIVE => 'Archive',
            self::FILE_TYPE_AUDIO   => 'Audio',
            self::FILE_TYPE_SLIDES  => 'Slides',
            self::FILE_TYPE_TEXT    => 'Text',
            self::FILE_TYPE_OTHER   => 'File',
        ][(int) $fileType] ?? 'File';
    }

    /** Whether sub-admins can see this document. */
    public function visibilityLabel(): string
    {
        return (int) $this->is_sub_admin_visible === 1 ? 'Visible' : 'Hidden';
    }
}
