<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Safety net only.
 *
 * Compression is normally triggered per upload: the upload spawns
 * "videos:compress --file=<name>" as a detached process the moment the file
 * is merged (VIDEO_COMPRESS_MODE=spawn). This hourly sweep exists purely to
 * catch anything that spawn missed - exec() disabled, no PHP CLI on PATH, a
 * server restart mid-encode.
 *
 * It is almost free when there is nothing to do: the command keeps a marker
 * per file, so a sweep with no new uploads is a directory listing and a few
 * stat calls. Delete this block if you would rather rely on the spawn alone.
 *
 * Overlap is prevented INSIDE the command with an flock, not with
 * withoutOverlapping(): that helper takes a lock from the cache store, and
 * this app's cache is the remote database. If that were briefly unreachable
 * the scheduled run would simply never happen.
 */
Schedule::command('videos:compress --min-mb=1')
    ->hourly()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/videos-compress.log'));
