<?php

namespace App\Console\Commands;

use App\Models\CompanyPersonalDocumentFile;
use Illuminate\Console\Command;

/**
 * Move existing personal-document files out of the web-servable upload
 * directory into private storage.
 *
 * New uploads are privatised automatically on save; this handles everything
 * uploaded before that change. Safe to re-run — already-private files are
 * skipped — and safe NOT to run: the model falls back to the old public path,
 * so documents keep working either way.
 */
class PrivatisePersonalDocuments extends Command
{
    protected $signature = 'documents:privatise {--dry-run : List what would move without moving anything}';

    protected $description = 'Move personal-document files out of the public uploads directory into private storage';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetDir = storage_path(CompanyPersonalDocumentFile::PRIVATE_DIR);

        if (!$dryRun && !is_dir($targetDir)
            && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $this->error("Could not create {$targetDir}");
            return self::FAILURE;
        }

        $files = CompanyPersonalDocumentFile::where('is_deleted', 0)->get();
        $moved = $skipped = $missing = $failed = 0;

        foreach ($files as $file) {
            if ($file->isPrivate()) {
                $skipped++;
                continue;
            }

            $source = $file->publicPath();
            if (!is_file($source)) {
                $this->warn("missing on disk: {$file->file_path}");
                $missing++;
                continue;
            }

            if ($dryRun) {
                $this->line("would move: {$file->file_path}");
                $moved++;
                continue;
            }

            if (@rename($source, $file->privatePath())) {
                $moved++;
            } else {
                $this->error("could not move: {$file->file_path}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d moved, %d already private, %d missing on disk, %d failed.',
            $dryRun ? '[dry run] ' : '',
            $moved,
            $skipped,
            $missing,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
