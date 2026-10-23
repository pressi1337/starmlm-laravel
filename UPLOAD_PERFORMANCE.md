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
