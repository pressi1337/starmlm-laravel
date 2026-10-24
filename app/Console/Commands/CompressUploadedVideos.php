<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Re-encode already-uploaded videos in place to cut user data usage.
 *
 * New uploads are compressed automatically by VideoUploadController; this
 * command is the one-time pass for everything uploaded before that existed.
 *
 * Filenames are preserved, so NO database changes are needed — every
 * daily_videos.video_path / promotion_videos.video_path / support_helps.video
 * keeps pointing at the same name, now a much smaller file.
 *
 * Safety:
 *   • originals are moved to uploads/original/ before replacing (unless
 *     --delete-originals), so anything can be rolled back;
 *   • the new file only replaces the old one if ffmpeg succeeded AND the
 *     result is actually smaller — otherwise the original is left untouched;
 *   • idempotent: already-small files are skipped, so it's safe to re-run.
 *
 * Typical use:
 *   php artisan videos:compress --dry-run       # see what would happen
 *   php artisan videos:compress --limit=3       # do a few, verify playback
 *   php artisan videos:compress                 # the rest
 */
class CompressUploadedVideos extends Command
{
    protected $signature = 'videos:compress
        {--dry-run : Only report what would be compressed, change nothing}
        {--limit=0 : Maximum number of files to process (0 = no limit)}
        {--min-mb=20 : Skip files smaller than this (MB)}
        {--height= : Target height in px (defaults to config services.ffmpeg.height)}
        {--crf= : Quality 18=best/large .. 32=small (defaults to config services.ffmpeg.crf)}
        {--delete-originals : Delete originals instead of keeping a backup copy}
        {--file= : Compress only this single filename}';

    protected $description = 'Re-encode already-uploaded videos in place to reduce app data usage';

    public function handle(): int
    {
        // Same binary + quality settings the upload flow uses, so existing and
        // new videos end up consistent. FFMPEG_PATH supports a static binary.
        $ffmpeg = config('services.ffmpeg.path', 'ffmpeg');
        $crf = $this->option('crf') !== null && $this->option('crf') !== ''
            ? (int) $this->option('crf')
            : (int) config('services.ffmpeg.crf', 24);
        $height = $this->option('height') !== null && $this->option('height') !== ''
            ? (int) $this->option('height')
            : (int) config('services.ffmpeg.height', 720);
        $crf = max(18, min(32, $crf));
        $height = max(360, min(1080, $height));

        if (!$this->ffmpegAvailable($ffmpeg)) {
            $this->error("ffmpeg not found (tried: {$ffmpeg}).");
            $this->line('Install it (apt install ffmpeg) or set FFMPEG_PATH to a static binary.');
            return self::FAILURE;
        }

        $dir = storage_path('app/public/uploads/final');
        if (!is_dir($dir)) {
            $this->error("Upload directory not found: {$dir}");
            return self::FAILURE;
        }

        $backupDir = storage_path('app/public/uploads/original');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $minBytes = max(0, (float) $this->option('min-mb')) * 1024 * 1024;
        $keepOriginals = !$this->option('delete-originals');

        if (!$dryRun && $keepOriginals && !is_dir($backupDir)
            && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $this->error("Could not create backup directory: {$backupDir}");
            return self::FAILURE;
        }

        $this->line("Using ffmpeg: {$ffmpeg}  |  {$height}p  CRF {$crf}");
        $this->newLine();

        $videoExt = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp'];
        $only = $this->option('file');

        $files = $only
            ? [$dir . DIRECTORY_SEPARATOR . $only]
            : (glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);

        $processed = 0;
        $savedBytes = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $path) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            if (!is_file($path)) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $videoExt, true)) {
                continue;
            }

            $name = basename($path);
            $originalSize = filesize($path) ?: 0;
            if ($originalSize < $minBytes) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  would compress  %-45s %s', $this->shorten($name), $this->mb($originalSize)));
                $processed++;
                continue;
            }

            $tmp = $path . '.compressing.mp4';
            @unlink($tmp);

            $process = new Process([
                $ffmpeg, '-y',
                '-i', $path,
                // min() prevents upscaling anything already smaller than target.
                '-vf', 'scale=-2:min(' . $height . '\,ih)',
                '-c:v', 'libx264',
                '-crf', (string) $crf,
                '-preset', 'medium',
                '-c:a', 'aac',
                '-b:a', '96k',
                // Index up front so playback starts without fetching the whole file.
                '-movflags', '+faststart',
                $tmp,
            ]);
            $process->setTimeout(7200);

            $this->line("  compressing     {$this->shorten($name)} ({$this->mb($originalSize)}) ...");
            $process->run();

            $newSize = is_file($tmp) ? (filesize($tmp) ?: 0) : 0;

            // Only accept the result if ffmpeg succeeded and it's genuinely smaller.
            if (!$process->isSuccessful() || $newSize < 10240) {
                @unlink($tmp);
                $failed++;
                $this->warn("    failed — original left untouched ({$name})");
                continue;
            }
            if ($newSize >= $originalSize) {
                @unlink($tmp);
                $skipped++;
                $this->line('    already efficient — kept original');
                continue;
            }

            if ($keepOriginals) {
                $backupPath = $backupDir . DIRECTORY_SEPARATOR . $name;
                if (!@rename($path, $backupPath)) {
                    @unlink($tmp);
                    $failed++;
                    $this->warn("    could not back up original — skipped ({$name})");
                    continue;
                }
            } else {
                @unlink($path);
            }

            if (!@rename($tmp, $path)) {
                $failed++;
                $this->error("    could not replace {$name} — backup is in uploads/original/");
                continue;
            }

            $saved = $originalSize - $newSize;
            $savedBytes += $saved;
            $processed++;
            $this->info(sprintf(
                '    done  %s -> %s  (saved %s, %d%%)',
                $this->mb($originalSize),
                $this->mb($newSize),
                $this->mb($saved),
                (int) round($saved / max(1, $originalSize) * 100)
            ));
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry run: {$processed} file(s) would be compressed, {$skipped} skipped (under the size threshold).");
            $this->line('Re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        $this->info("Compressed: {$processed} | Skipped: {$skipped} | Failed: {$failed} | Total saved: {$this->mb($savedBytes)}");
        if ($keepOriginals && $processed > 0) {
            $this->line('Originals kept in storage/app/public/uploads/original/ — delete them once you have verified playback.');
        }

        return self::SUCCESS;
    }

    private function ffmpegAvailable(string $ffmpeg): bool
    {
        try {
            $p = new Process([$ffmpeg, '-version']);
            $p->setTimeout(20);
            $p->run();
            return $p->isSuccessful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function mb(float $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    private function shorten(string $name, int $len = 42): string
    {
        return strlen($name) <= $len ? $name : substr($name, 0, $len - 3) . '...';
    }
}
