<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Re-encode a merged upload in place (720p H.264, AAC, faststart).
 *
 * WHY THIS IS A QUEUED JOB AND NOT defer()
 * ----------------------------------------
 * This used to run via defer(), which fires after the response but still
 * inside the PHP-FPM worker that served the request — with a 2-hour timeout.
 * A single 10-minute encode therefore held one worker for 10 minutes. Two or
 * three uploads in a row exhausted the pool and the whole API crawled, which
 * made every other upload look slow as well. Queueing it hands the encode to
 * a separate long-running process and returns the worker immediately.
 *
 * Deliberately defensive — an upload must never be lost because of this:
 *   • no ffmpeg on the server            -> keep the original, log why;
 *   • encode fails / output looks broken -> keep the original;
 *   • result isn't smaller               -> keep the original.
 * The filename never changes, so nothing downstream has to know. Until the
 * encode lands the original plays fine under the same name; rename() then
 * swaps the compressed file in atomically.
 */
/*
 * Deliberately NOT ShouldBeUnique.
 *
 * It looks like a sensible guard, but it would be dead weight with a real
 * downside here. The id would be the file path, and every upload is stored
 * under its own uuid-prefixed name, so two dispatches can never share a path
 * — there is nothing to deduplicate. Meanwhile ShouldBeUnique has to take a
 * lock from the cache store before dispatching, and this app's cache lives in
 * the (remote) database: if that lock cannot be acquired, the job is silently
 * never queued and videos quietly stop being compressed. The merge is already
 * serialised by an flock, so one upload produces exactly one dispatch.
 */
class CompressUploadedVideo implements ShouldQueue
{
    use Queueable;

    /** One attempt: a failed encode keeps the original, so retrying buys nothing. */
    public int $tries = 1;

    /**
     * Long videos are legitimately slow; the worker is not holding a request.
     *
     * The worker's own --timeout must be LARGER than this or it kills the job
     * mid-encode. See UPLOAD_PERFORMANCE.md for the unit file.
     */
    public int $timeout = 7200;

    public function __construct(public string $path)
    {
    }

    /**
     * tries = 1, so anything thrown lands here. The original file is always
     * left intact, so a failure costs quality, never the upload.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('Video compression job failed; original kept', [
            'file' => basename($this->path),
            'error' => $e?->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $path = $this->path;

        try {
            $videoExt = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp'];
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $videoExt, true)) {
                return; // not a video — nothing to do
            }

            $originalSize = is_file($path) ? (@filesize($path) ?: 0) : 0;
            if ($originalSize <= 0) {
                Log::warning('Video compression skipped: file missing or empty', ['file' => basename($path)]);
                return;
            }

            // "ffmpeg" when it's on the system PATH; an absolute path can be
            // set via FFMPEG_PATH for hosts where you can't install packages.
            $ffmpeg = config('services.ffmpeg.path', 'ffmpeg');
            // Clamped to sane bounds so a bad .env value can't wreck output.
            $crf = max(18, min(32, (int) config('services.ffmpeg.crf', 24)));
            $height = max(360, min(1080, (int) config('services.ffmpeg.height', 720)));

            $probe = new Process([$ffmpeg, '-version']);
            $probe->setTimeout(20);
            $probe->run();
            if (!$probe->isSuccessful()) {
                Log::warning('ffmpeg not installed; upload kept at original size', [
                    'file' => basename($path),
                ]);
                return;
            }

            $tmp = $path . '.compressed.mp4';
            @unlink($tmp);

            $process = new Process([
                $ffmpeg, '-y',
                '-i', $path,
                // Cap height at 720p; min() keeps smaller videos from being
                // upscaled. The comma is escaped for ffmpeg's filter parser.
                '-vf', 'scale=-2:min(' . $height . '\,ih)',
                '-c:v', 'libx264',
                '-crf', (string) $crf,
                // Now that this runs on a queue worker rather than holding a
                // web request, "veryfast" is no longer needed to keep the
                // admin waiting less — "fast" trades a little CPU for size.
                '-preset', 'fast',
                '-c:a', 'aac',
                '-b:a', '96k',
                // Index at the front so playback starts without fetching the
                // whole file — a large part of the data saving.
                '-movflags', '+faststart',
                $tmp,
            ]);
            $process->setTimeout($this->timeout);
            $process->run();

            $newSize = is_file($tmp) ? (@filesize($tmp) ?: 0) : 0;

            if (!$process->isSuccessful() || $newSize < 10240) {
                @unlink($tmp);
                Log::warning('Video compression failed; original kept', [
                    'file'  => basename($path),
                    'error' => substr($process->getErrorOutput(), -500),
                ]);
                return;
            }

            if ($newSize >= $originalSize) {
                @unlink($tmp);
                Log::info('Video already optimised; original kept', ['file' => basename($path)]);
                return;
            }

            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                Log::warning('Compressed video could not replace the original', [
                    'file' => basename($path),
                ]);
                return;
            }

            Log::info('Video compressed after upload', [
                'file' => basename($path),
                'from' => round($originalSize / 1048576, 1) . ' MB',
                'to'   => round($newSize / 1048576, 1) . ' MB',
            ]);
        } catch (\Throwable $e) {
            Log::error('Video compression error; original kept', [
                'file'  => basename($path),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
