<?php

namespace App\Console\Commands;

use App\Services\UploadedFileCleaner;
use Illuminate\Console\Command;

/**
 * Delete uploaded files that no live record references any more.
 *
 * Deletes and edits now clean up after themselves, but everything removed
 * BEFORE that change left its file on disk. This reclaims that backlog.
 *
 * Two safety rails:
 *   • a file is only touched when no live row anywhere references it
 *     (same check the runtime cleaner uses);
 *   • a file must be older than --hours (default 24). A fresh upload sitting
 *     in the directory while an admin is still filling in the form has no
 *     database row yet and would otherwise look exactly like an orphan.
 */
class PruneOrphanUploads extends Command
{
    protected $signature = 'storage:prune-orphans
        {--dry-run : List what would be deleted without deleting anything}
        {--hours=24 : Only consider files older than this many hours}';

    protected $description = 'Delete uploaded files no live record references any more';

    private array $directories = [
        'app/public/uploads/final',
        'app/public/uploads/original',
        'app/personal-documents',
    ];

    public function handle(UploadedFileCleaner $cleaner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = max(0, (int) $this->option('hours'));
        $cutoff = time() - ($hours * 3600);

        $deleted = 0;
        $keptReferenced = 0;
        $keptTooNew = 0;
        $bytes = 0;

        foreach ($this->directories as $relative) {
            $dir = storage_path($relative);
            if (!is_dir($dir)) {
                continue;
            }

            $this->line("Scanning {$relative} …");

            foreach (glob($dir . '/*') ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $name = basename($path);

                if (filemtime($path) >= $cutoff) {
                    $keptTooNew++;
                    continue;
                }

                if ($cleaner->isReferenced($name)) {
                    $keptReferenced++;
                    continue;
                }

                $size = (int) @filesize($path);

                if ($dryRun) {
                    $this->line(sprintf('  would delete  %-60s %s', $name, $this->human($size)));
                    $deleted++;
                    $bytes += $size;
                    continue;
                }

                if (@unlink($path)) {
                    $deleted++;
                    $bytes += $size;
                } else {
                    $this->error("  could not delete {$name}");
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d orphan file(s), %s reclaimed. Kept %d still referenced, %d newer than %dh.',
            $dryRun ? '[dry run] ' : '',
            $deleted,
            $this->human($bytes),
            $keptReferenced,
            $keptTooNew,
            $hours
        ));

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
