# Video upload performance

**No server changes are required.** Everything below works on the server as it
is configured today. The optional section at the end buys more speed later.

## What was measured, against the live API (23 Oct 2026)

```
POST https://api.starupworld.com/api/v1/upload
  first request (new TLS connection) .......... 0.77 s
  subsequent on the same connection ........... 0.20 – 0.30 s
  round trip (TCP connect) to the host ........ 0.21 s
  nginx 1.26.3, HTTP/1.1 only (no HTTP/2)
  client_max_body_size ........................ 8 MB  (6 MB accepted, 8 MB refused with 413)
```

Those timings are for an **unauthenticated 401**, which short-circuits before
the controller does any work. A real chunk request costs more: JWT decode, a
user lookup, multipart parsing, the disk write and the JSON response.

The body-size ceiling was found by POSTing bodies of increasing size and
watching for the 413, which nginx returns before PHP is involved. PHP's own
`upload_max_filesize` / `post_max_size` sit behind it and are **not** visible
from outside — an unauthenticated request is rejected before validation runs,
so the limit cannot be inferred. The uploader therefore discovers it at
runtime instead of assuming (see below).

## Why uploads were slow

The uploader sent **1 MB chunks, strictly one at a time** — `await` inside a
`for` loop, in all three upload components. Each chunk is a separate HTTPS
request, so the cost above was paid once per megabyte, serially, with nothing
overlapping.

| Video | Chunks | Sequential round trips | Overhead alone (~0.45 s each) |
|-------|--------|------------------------|-------------------------------|
| 100 MB | 100 | 100 | ~45 s |
| 500 MB | 500 | 500 | **~3.7 min** |
| 1 GB   | 1024 | 1024 | **~7.7 min** |

Almost none of that is data transfer — it is per-request overhead. A faster
connection did not help, which is why it felt broken rather than merely slow.

Three further problems made it worse:

1. **An O(n²) filesystem scan.** Every request called `File::exists()` once per
   chunk index to decide whether the upload was complete. For a 500 MB file at
   1 MB chunks that is ~250,000 `stat` calls across the upload.
2. **No retries.** A single dropped chunk failed the upload, and the old loop
   caught the error per chunk and *carried on regardless*, leaving a
   half-uploaded file behind with no usable error.
3. **A truncated-video bug.** If a chunk went missing, the warning raised when
   opening it was converted to an exception, which skipped the cleanup and left
   a short, playable video in `final/`.

## What changed

### Client — one shared uploader

`src/utils/chunkedUpload.ts`, used by all three components (`FileUpload.tsx` in
both projects and `MultiFileUpload.tsx`) so they cannot drift apart again:

- **4 MB chunks** instead of 1 MB — four times fewer requests, and it fits
  under the measured 8 MB nginx ceiling with room to spare.
- **6 chunks in flight at once** — the round trips overlap instead of queueing.
- Per-chunk **retry with backoff** (3 attempts) for network blips. Permanent
  failures fail fast rather than retrying pointlessly.
- Progress driven by **bytes acknowledged**, so the bar stays honest when
  chunks finish out of order, and never shows 100% before the merge confirms.

For a 500 MB video: **500 sequential requests become 125 chunks in ~22 rounds.**
The upload is then bound by actual bandwidth rather than latency.

### It sizes itself to the server

How large a chunk may be is the server's decision and is not fully
discoverable in advance. So the **first chunk is sent on its own as a probe**.
If the server refuses it for being too large, the chunk size is halved and the
upload restarts — and because only that one chunk had been sent, each retry
costs a single round trip. It stops at a 512 KB floor.

Two different refusals mean "too large", and both are handled:

- **413** — nginx rejected the body before PHP saw it.
- **422 naming the upload fields** — PHP silently discarded the multipart body
  for exceeding `upload_max_filesize` / `post_max_size`, so the fields arrive
  empty and validation fails on them.

This is what makes the change safe to deploy without touching the server: if
PHP turns out to be at its 2 MB default, uploads still complete, just with
smaller chunks. Raise the limits later and it automatically uses bigger ones.

The probe is not an extra request — chunk 0 has to be sent anyway. It only
loses the parallelism of that one chunk.

### The last chunk is sent alone, deliberately

The server merges as soon as it sees a complete set and returns the stored
filename from that request. If the final two chunks were in flight together,
two requests could both see a complete set and race to merge. So every chunk
between the first and the last goes up in parallel, and only once those have
landed is the last one sent on its own — exactly one request completes the set.
The server also takes an exclusive lock, as defence in depth.

### Server — `VideoUploadController::upload()`

- Completion is **one directory read** (`glob`) instead of N `stat` calls.
- The merge **verifies every chunk index exists before creating the output
  file**, so a gap can no longer leave a truncated video in `final/`, and
  cleanup now runs even when a PHP warning is raised as an exception.
- The merge opens the target with `wb`, not `ab` — appending could silently
  concatenate onto an existing file.
- The client-supplied `filename` is **sanitised**. It is interpolated into a
  storage path, so `../../` in it could previously write outside the storage
  directory.
- Dropped `ini_set('post_max_size')` / `ini_set('upload_max_filesize')`: both
  are `PHP_INI_PERDIR` and cannot be set at runtime — the request body is
  already parsed by the time the controller runs. They were doing nothing.

## Compression: `VIDEO_COMPRESS_MODE`

The encode lives in `App\Jobs\CompressUploadedVideo`. Only *where* it runs is
configurable, because that depends on what the server has:

| mode | needs | effect |
|------|-------|--------|
| `defer` **(default)** | nothing | Runs after the response, in the PHP-FPM worker that served the upload. |
| `queue` | a running queue worker | Hands it off and frees the web worker immediately. |
| `off` | nothing | Serve exactly what was uploaded. |

**The default is `defer`, which is the existing behaviour — so nothing changes
and nothing stops working.**

Be aware of what `defer` costs: the response is sent first, but the worker is
then held for the whole encode (minutes). A few uploads in a row can tie up the
FPM pool and make the **whole API** feel slow — which is worth knowing, because
it can look like an upload problem when it is really worker starvation.

Switching to `queue` removes that, but **only works with a worker running**;
without one, videos are never compressed and silently stay at full size. Set it
up when you can (below), then set `VIDEO_COMPRESS_MODE=queue`.

ffmpeg must be installed either way, or the job logs `ffmpeg not installed` and
keeps the original — uploads are never lost:

```bash
ffmpeg -version
```

---

# Optional — for further gains, when server access is available

None of this is needed for the change above to work.

## 1. Raise the body limit, so chunks can be bigger

Currently `client_max_body_size` is **8M**, which is why the chunk default is
4 MB. Raising it lets each request carry more and cuts the round trips further.

In the `server` block for `api.starupworld.com`:

```nginx
client_max_body_size 16M;
client_body_buffer_size 1M;
client_body_timeout 300s;
send_timeout 300s;
fastcgi_read_timeout 300s;
```

And match it in the FPM pool's `php.ini` (locate with `php --ini`) — these only
need to fit **one chunk**, not the whole video:

```ini
upload_max_filesize = 16M
post_max_size = 16M
max_execution_time = 300
memory_limit = 512M
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm      # match your PHP version
```

Then set `VITE_UPLOAD_CHUNK_MB=12` and rebuild. (If you skip the rebuild
nothing breaks — the uploader simply keeps using 4 MB.)

## 2. Enable HTTP/2, so more chunks can run at once

The API is HTTP/1.1, which caps the browser at ~6 connections per host — the
reason concurrency is 6. HTTP/2 multiplexes over one connection:

```nginx
listen 443 ssl;
http2 on;          # nginx >= 1.25.1; you are on 1.26.3
```

Then `VITE_UPLOAD_CONCURRENCY=10` is reasonable.

## 3. A queue worker, to stop encodes holding web workers

`QUEUE_CONNECTION=database` is already set and the `jobs` table already exists.

```bash
sudo tee /etc/systemd/system/starmlm-queue.service >/dev/null <<'UNIT'
[Unit]
Description=StarMLM queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/starmlm-laravel
# --timeout must exceed the job's own timeout (7200s) for long encodes.
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --timeout=7800

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now starmlm-queue
sudo systemctl status starmlm-queue
```

Adjust `WorkingDirectory` and the PHP path to the real deployment. Then set
`VIDEO_COMPRESS_MODE=queue` in `.env` and `php artisan config:clear`.

Check nothing is piling up (both should be 0 or near it):

```sql
SELECT COUNT(*) AS pending FROM jobs;
SELECT COUNT(*) AS failed  FROM failed_jobs;
```

Restart the worker after every deploy, or it keeps running the old code:

```bash
sudo systemctl restart starmlm-queue
```

---

# How to inspect the server yourself

## Without SSH, from any machine

Find the effective `client_max_body_size` — nginx answers 413 before PHP is
involved, so no login is needed. Raise the size until it flips:

```bash
head -c 6000000 /dev/urandom > /tmp/probe.bin
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  -F "videofile=@/tmp/probe.bin" -F "filename=probe.bin" \
  -F "chunkIndex=0" -F "totalChunks=1" \
  https://api.starupworld.com/api/v1/upload
# 401 = nginx accepted the body (PHP then rejected the missing token)
# 413 = nginx refused it -> you have found the ceiling
```

Protocol version, server build and latency:

```bash
curl -sI https://api.starupworld.com/ | head -5
curl -s -o /dev/null -w "http=%{http_version} ttfb=%{time_starttransfer}s\n" \
  https://api.starupworld.com/api/v1/upload -X POST
```

## With SSH

```bash
# PHP's own limits, as the FPM pool actually sees them
php --ini                     # which files are loaded
php -i | grep -E "^(post_max_size|upload_max_filesize|memory_limit|max_execution_time|max_file_uploads)"

# nginx: every size/timeout directive in force, and where it is set
sudo nginx -T 2>/dev/null | grep -nE "client_max_body_size|client_body|_timeout|listen|http2"
nginx -v

# FPM pool size — how many concurrent requests are possible at all
grep -rE "^(pm|pm\.max_children|pm\.start_servers|pm\.max_requests)" /etc/php/*/fpm/pool.d/
systemctl status php*-fpm --no-pager | head -5

# Is a queue worker running? (decides whether VIDEO_COMPRESS_MODE=queue is safe)
ps aux | grep -c "[q]ueue:work"
systemctl status starmlm-queue --no-pager 2>/dev/null | head -5

# ffmpeg present?
ffmpeg -version | head -1 || echo "ffmpeg NOT installed"

# Free space where videos land, and leftover part files from failed uploads
df -h /var/www
du -sh storage/app/public/uploads/final storage/app/uploads/tmp
find storage/app/uploads/tmp -mindepth 1 -maxdepth 1 -mtime +1
```

A quick PHP-side dump of what the app itself sees:

```bash
php artisan tinker --execute="
  echo 'post_max_size:        ', ini_get('post_max_size'), PHP_EOL;
  echo 'upload_max_filesize:  ', ini_get('upload_max_filesize'), PHP_EOL;
  echo 'memory_limit:         ', ini_get('memory_limit'), PHP_EOL;
  echo 'compress mode:        ', config('services.ffmpeg.mode'), PHP_EOL;
  echo 'queue connection:     ', config('queue.default'), PHP_EOL;
  echo 'pending jobs:         ', DB::table('jobs')->count(), PHP_EOL;
"
```

## If an upload fails after deploying

The admin screen now reports the real cause rather than a generic failure. A
size refusal reads:

> The server refused a 4 MB chunk (HTTP 413). Raise nginx client_max_body_size
> and PHP post_max_size above the chunk size, or lower VITE_UPLOAD_CHUNK_MB.

That should not happen on this server — the uploader halves itself down to
512 KB before giving up — but if it does, set `VITE_UPLOAD_CHUNK_MB=1` and
rebuild as an immediate unblock. Uploads stay correct and still get the
parallelism, just with more requests.

---

# Measured on dev, after deploying (23 Oct 2026)

A real 40 MB upload through the deployed client bundle, then the same file the
old way, back to back on the same connection:

```
OLD  1 MB chunks, serial     178.6 s    (0.22 MB/s)   avg 4466 ms per chunk
NEW  deployed uploader        33.6 s    (1.19 MB/s)
                            --------
                              5.3x faster, 145 s saved on 40 MB
```

The absolute figures are capped by the test machine's upstream bandwidth; the
**ratio** is the meaningful number, since both runs shared one connection.

## The real bottleneck was PHP, not nginx

The uploader reported `chunk size settled on: 2 MB` — it had started at 4 MB
and halved. Probing with a valid token pinned why:

```
2048 KB chunk -> 200 OK
2560 KB chunk -> 422  {"errors":{"videofile":["The videofile failed to upload."]}}
3072 KB chunk -> 422
4096 KB chunk -> 422
```

So `upload_max_filesize = 2M` — the stock PHP default. nginx's 8 MB
`client_max_body_size` was never the binding limit.

**This is why the adaptive probe matters:** without it the deploy would have
failed every upload with that 422. Instead it settled on 2 MB and still ran
5.3x faster.

Raising PHP's limit is now the single highest-value server change — it lifts
the cap the uploader is currently working around:

```ini
upload_max_filesize = 16M
post_max_size = 16M
```

At 4 MB chunks expect roughly double the throughput again; `VITE_UPLOAD_CHUNK_MB=12`
(after also raising nginx to 16M) more still.

---

# Clearing orphaned videos

`POST /api/v1/upload/delete` **never deleted anything** before the fix in this
change. It resolved the path through the default disk, whose root is
`storage/app/private` on Laravel 12, so it looked in
`storage/app/private/public/uploads/final/` while uploads land in
`storage/app/public/uploads/final/`. It always answered "File not found", and
every replaced or removed video stayed on disk.

Expect a backlog. To find it, list stored videos that no DB row references:

```sql
-- Names currently referenced by content rows (add any other table that
-- stores a video filename).
SELECT video_url FROM daily_videos      WHERE is_deleted = 0
UNION SELECT video_url FROM training_videos  WHERE is_deleted = 0
UNION SELECT video_url FROM promotion_videos WHERE is_deleted = 0;
```

Then compare against the directory and remove what is unreferenced:

```bash
cd /var/www/starmlm-laravel
du -sh storage/app/public/uploads/final          # how big is the backlog
ls -la storage/app/public/uploads/final | head

# Leftover part files from uploads that never finished (safe to remove once
# nothing is mid-upload):
find storage/app/uploads/tmp -mindepth 1 -maxdepth 1 -mtime +1 -exec rm -rf {} +
```

Check a filename against the database before deleting it — some videos are
referenced from more than one table.

---

# THE 504: why real video uploads were failing

Found by uploading a genuinely decodable video on dev (24 Oct 2026). Earlier
tests used synthetic bytes with an `.mp4` name, which ffmpeg refuses to decode
— so they never exercised the encode and never showed this.

Same 7.82 MB of bytes uploaded twice, changing only the extension:

```
.bin   compressInPlace() bails on the extension, no ffmpeg
       -> final chunk: HTTP 200 in 3.1s

.webm  a real decodable video, ffmpeg runs
       -> final chunk: HTTP 504 Gateway Time-out after 63.9s
```

63.9s is nginx's default `fastcgi_read_timeout`. The merge itself takes 3.1s.

**So the encode was blocking the response until the gateway gave up.** The
file merged fine on disk, but the client never received `stored_filename`, so
the admin saw "Upload failed please retry again" at 100% and could not save
the record. **Every real video upload failed.** Synthetic test files passed,
which is exactly why this hid for so long.

The code already carried a comment saying the encode had been moved into
`defer()` to fix this. `defer()` runs after the response is *sent*, but on this
PHP-FPM setup that is not enough to release the FastCGI connection, so nginx
kept waiting.

## The fix

`VIDEO_COMPRESS_MODE` now defaults to **`queue`**, which cannot block the
request. The in-code fallback is `queue` too, so a stale `config:cache` from
before this setting existed cannot land on the blocking mode. An unrecognised
value now skips compression and logs, rather than risking the upload.

`defer` mode additionally calls `fastcgi_finish_request()` before encoding, so
it is correct where PHP allows it — but it is no longer the default, because
if that function is disabled the request blocks and uploads break again.

## Pick one of these so videos still get compressed

ffmpeg IS installed on the server (it ran for 64 seconds), so either works.

**A — queue worker** (matches the new default; see the systemd unit above):

```bash
sudo systemctl enable --now starmlm-queue
```

**B — cron, no worker needed.** Set `VIDEO_COMPRESS_MODE=off` and let the
existing backfill command do it. It is idempotent, keeps the originals, skips
files already small, and only replaces a file when the result is genuinely
smaller:

```cron
*/10 * * * * cd /var/www/backend && php artisan videos:compress >> storage/logs/compress.log 2>&1
```

Note its `--min-mb=20` default skips files under 20 MB; pass `--min-mb=2` to
catch smaller ones.

Either way, check it is happening:

```bash
php artisan videos:compress --dry-run
tail -50 storage/logs/laravel.log | grep -i -E "compress|ffmpeg"
```

---

# THE QUEUE CHANGEOVER — what to run, with no server config changes

Constraints this is built for: **nginx and php.ini cannot be changed, but a
queue worker can be run.** Nothing below needs root beyond starting the worker.

## Does the merge need queueing too? No — measured

The merge runs inside the final chunk's request, so it was worth checking
whether it would hit the same 60s gateway limit on a large video:

```
 8 MB -> final chunk HTTP 200 in 2.4s
24 MB -> final chunk HTTP 200 in 2.5s
48 MB -> final chunk HTTP 200 in 1.4s
```

Flat — it does not scale with file size, because `stream_copy_to_stream` is a
kernel-level copy on local disk (~35 MB/s observed). The cost is almost all
fixed overhead. A 500 MB merge is therefore ~15s and a 1 GB merge ~30s, both
inside the 60s limit.

**So only the ffmpeg encode ever needed to leave the request** — which is what
`VIDEO_COMPRESS_MODE=queue` does. The chunk endpoint stays bounded: each
request writes at most one chunk (2 MB, the PHP cap) and the last one does a
fast merge plus a `dispatch()`.

## 1. Start the worker

`QUEUE_CONNECTION=database` and `CACHE_STORE=database` are already set, and the
`jobs`, `failed_jobs`, `cache` and `cache_locks` tables all exist, so there is
nothing to install.

```bash
sudo tee /etc/systemd/system/starmlm-queue.service >/dev/null <<'UNIT'
[Unit]
Description=StarMLM queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/backend
# --timeout MUST exceed the job's own timeout (7200s) or the worker kills the
# encode partway. --tries=1 because a failed encode keeps the original, so a
# retry buys nothing.
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --timeout=7800

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now starmlm-queue
sudo systemctl status starmlm-queue --no-pager
```

Adjust `WorkingDirectory` and the PHP path if the deployment differs. **Restart
the worker after every deploy**, or it keeps executing the old code:

```bash
sudo systemctl restart starmlm-queue
```

If systemd is not available, a supervisor program block or even
`nohup php artisan queue:work --tries=1 --timeout=7800 &` works — the job only
needs *some* long-running consumer.

## 2. Set the mode (it is already the default)

```ini
VIDEO_COMPRESS_MODE=queue
```

The in-code fallback is `queue` as well, so a `config:cache` predating this
setting cannot land on the blocking `defer` path. After changing `.env`:

```bash
php artisan config:clear
```

## 3. Confirm it is working

```bash
# Should be 0 or briefly 1. A number that only grows = the worker is not running.
php artisan tinker --execute="echo DB::table('jobs')->count(), PHP_EOL;"

# Should stay 0.
php artisan tinker --execute="echo DB::table('failed_jobs')->count(), PHP_EOL;"

# The encode logs its result either way.
tail -50 storage/logs/laravel.log | grep -iE "compress|ffmpeg"
```

Expected log lines: `Video compressed after upload` with before/after sizes, or
`ffmpeg not installed` / `Video compression failed; original kept`. In every
failure case the original file is kept and the upload is unaffected.

End-to-end proof that a video really was compressed: note its size right after
upload, then again a minute later.

```bash
curl -sI https://api.starupworld.com/storage/uploads/final/<stored_filename> | grep -i content-length
```

## Job safety properties

`App\Jobs\CompressUploadedVideo`:

- `ShouldBeUnique` + `uniqueId()` = the same file cannot be queued twice.
  (The interface is required — `uniqueId()` alone is ignored by the queue.)
- `uniqueFor = 7260` so a worker killed mid-encode cannot wedge the lock
  permanently.
- `tries = 1` — a failed encode keeps the original, so retrying gains nothing.
- `failed()` logs the reason.
- Every failure path keeps the original file: missing ffmpeg, a failed encode,
  output that is not smaller, or a rename that does not succeed.
- The filename never changes, so no database row needs updating.

## If you cannot keep a worker running

Set `VIDEO_COMPRESS_MODE=off` and cron the existing backfill command instead —
same encode, same safety, no worker:

```cron
*/10 * * * * cd /var/www/backend && php artisan videos:compress --min-mb=2 >> storage/logs/compress.log 2>&1
```

---

# FINAL DESIGN: compression is triggered per upload

Supersedes the queue and scheduled-sweep sections above. Constraints this is
built for: **nginx and php.ini cannot be changed.** No worker and no cron are
required either.

## How it works

```
upload request  ->  writes one chunk (max 2 MB, the PHP cap)

final chunk     ->  merges the parts (~1.4-2.5s, flat regardless of size)
                    spawns a DETACHED process:
                        php artisan videos:compress --min-mb=1 --file=<name>
                    and returns immediately.
                    No ffmpeg in the request. It cannot 504 on an encode.

detached child  ->  compresses just that one file, in the background.
                    Owned by the OS, not by the PHP-FPM worker.
```

Measured locally: two chunks, the merge, and the spawn together take **0.19s**.
The child's output goes to `storage/logs/videos-compress.log`, so a spawn can
always be diagnosed after the fact.

`VIDEO_COMPRESS_MODE` defaults to **`spawn`** in config *and* in the
controller's own fallback, so even a stale `config:cache` cannot reach the
`defer` path that caused the 504.

## Setup

Nothing to start. Two things to check once:

```bash
# 1. ffmpeg must be installed (it is on this server - it ran for 64s).
ffmpeg -version | head -1

# 2. The PHP **CLI** must be reachable by the web user.
sudo -u www-data php -v
```

If step 2 fails, set an absolute path — this matters because `PHP_BINARY`
under PHP-FPM points at the FPM binary, which will not run artisan:

```ini
PHP_CLI_BINARY=/usr/bin/php8.2
```

Then `php artisan config:clear`.

## Old files

Not handled automatically, by design — run it once yourself:

```bash
php artisan videos:compress --dry-run --min-mb=1    # see what it would do
php artisan videos:compress --min-mb=1 --limit=3    # a few, verify playback
php artisan videos:compress --min-mb=1              # the rest
```

## The hourly sweep is a safety net, not the mechanism

`routes/console.php` also schedules an hourly `videos:compress`. It exists only
to catch what the spawn could not do — `exec()` disabled, no CLI on PATH, a
restart mid-encode. It is nearly free when there is nothing to do, because the
command skips files it has already handled. **Delete that block if you would
rather rely on the spawn alone**; it needs `schedule:work` or a `schedule:run`
cron entry to fire at all, and nothing breaks if neither exists.

## What makes repeated runs safe

The command was written as a one-time backfill, so two things were fixed:

1. **It had no memory of what it had compressed.** It judged purely on file
   size, and re-encoding an already-compressed file at the same CRF yields a
   slightly *smaller* file, which its size check accepts — so repeated runs
   would have thrown away a generation of quality each time. It now writes a
   marker per file under `storage/app/uploads/compress-state/`, keyed on
   **size + mtime**: handled files are skipped, a video *replaced* under the
   same name is picked up again, and a permanently undecodable file is marked
   so it is not retried forever. `--force` ignores all markers.

2. **Runs could overlap**, with two processes writing the same
   `.compressing.mp4` temp path. The command now takes an exclusive **flock**
   for the whole run and exits quietly if another run holds it. Deliberately
   not `withoutOverlapping()`, which takes a lock from the cache store — this
   app's cache is the remote database, so a blip there would silently stop
   compression altogether.

## Safety of the spawn

- The filename is sanitised to `[A-Za-z0-9._-]` by `safeFilename()` before it
  is ever used, and passed through `escapeshellarg()` on top. Shell
  metacharacters, command substitution and newlines cannot survive — there are
  tests for each.
- A spawn that cannot start (functions disabled, wrong CLI path) logs a warning
  and **the upload still succeeds**; the video is just left uncompressed.
- Every ffmpeg failure path keeps the original file, and the filename never
  changes, so no database row ever needs updating.

## Checking it

```bash
tail -30 storage/logs/videos-compress.log       # every spawn logs here
ls storage/app/uploads/compress-state | wc -l   # files handled so far
php artisan videos:compress --dry-run --min-mb=1
```

Prove a given video shrank — note the size right after upload, then again a
minute later:

```bash
curl -sI https://api.starupworld.com/storage/uploads/final/<name> | grep -i content-length
```

## One thing to watch: disk

The command **keeps every original** in `storage/app/public/uploads/original/`
so anything can be rolled back. That grows forever. Once you trust playback,
either prune it:

```bash
find storage/app/public/uploads/original -type f -mtime +30 -delete
```

or add `--delete-originals` to the spawn in `VideoUploadController` and to the
scheduled sweep.
