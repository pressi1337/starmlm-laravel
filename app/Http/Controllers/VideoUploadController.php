<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Jobs\CompressUploadedVideo;


class VideoUploadController extends Controller
{
    private $tempPath = 'uploads/tmp/';
    private $finalPath = 'public/uploads/final/';

    public function upload(Request $request)
    {
        // NOTE: upload_max_filesize and post_max_size are PHP_INI_PERDIR —
        // ini_set() cannot change them here, because the request body has
        // already been parsed by the time this runs. They must be set in
        // php.ini / the FPM pool. Only these two are settable at runtime.
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'videofile' => 'required|file',
            'filename' => 'required|string|max:255',
            'chunkIndex' => 'required|integer|min:0',
            'totalChunks' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $chunk = $request->file('videofile');
        // "filename" is client-supplied and is interpolated into a storage
        // path below, so "../../" in it would otherwise let an upload escape
        // the storage directory.
        $filename = $this->safeFilename($request->input('filename'));
        if ($filename === '') {
            return response()->json(['errors' => ['filename' => ['Invalid filename']]], 422);
        }
        $chunkIndex = (int) $request->input('chunkIndex');
        $totalChunks = (int) $request->input('totalChunks');

        if ($chunkIndex >= $totalChunks) {
            return response()->json(['errors' => ['chunkIndex' => ['Chunk index out of range']]], 422);
        }

        $tempDir = storage_path("app/{$this->tempPath}{$filename}");
        $finalDir = storage_path("app/{$this->finalPath}");

        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0777, true);
        }

        // Save current chunk
        $chunk->move($tempDir, "part-{$chunkIndex}");

        // How many chunks have landed? This used to call File::exists() once
        // per chunk index on EVERY request — O(totalChunks) stat calls per
        // request, so O(totalChunks^2) over an upload (a 500 MB file at 1 MB
        // chunks meant ~250,000 stat calls). One directory read replaces it.
        $present = count(glob("{$tempDir}/part-*") ?: []);

        if ($present < $totalChunks) {
            return response()->json([
                'message' => 'Chunk uploaded',
                'status' => 'chunk_uploaded',
                'received' => $present,
                'totalChunks' => $totalChunks,
                'filename' => $filename,
            ]);
        }

        // Every chunk is present, so this request merges. Chunks are now
        // uploaded in parallel, and the client deliberately sends the final
        // chunk on its own once the rest have landed, so exactly one request
        // reaches this point. The lock is defence in depth: without it two
        // requests arriving together would both concatenate into the same
        // file and produce a corrupt video.
        $lockHandle = fopen($tempDir . '.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle !== false) {
                fclose($lockHandle);
            }
            // Another request got there first; it will return the stored name.
            return response()->json([
                'message' => 'Merge already in progress',
                'status' => 'chunk_uploaded',
                'received' => $present,
                'totalChunks' => $totalChunks,
                'filename' => $filename,
            ]);
        }

        $finalPath = null;
        $output = null;

        try {
            if (!File::exists($finalDir)) {
                File::makeDirectory($finalDir, 0777, true);
            }

            // Confirm the whole set is present BEFORE creating the output
            // file. The count above can reach totalChunks while an index is
            // actually missing (a re-sent chunk, a stray part file), and
            // discovering that halfway through the copy would leave a
            // truncated video sitting in final/ for someone to play.
            $chunkPaths = [];
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$tempDir}/part-{$i}";
                if (!is_file($chunkPath)) {
                    throw new \RuntimeException("Chunk {$i} is missing; upload incomplete");
                }
                $chunkPaths[$i] = $chunkPath;
            }

            $storedName = Str::uuid() . '_' . $filename; // safe unique name
            $finalPath = "{$finalDir}/{$storedName}";

            // 'wb', not 'ab': appending would silently concatenate onto an
            // existing file if this ever ran twice for the same name.
            $output = fopen($finalPath, 'wb');
            if ($output === false) {
                throw new \RuntimeException('Could not open the merge target for writing');
            }

            foreach ($chunkPaths as $chunkPath) {
                $chunkStream = fopen($chunkPath, 'rb');
                if ($chunkStream === false) {
                    throw new \RuntimeException('A chunk became unreadable during the merge');
                }
                stream_copy_to_stream($chunkStream, $output);
                fclose($chunkStream);
                File::delete($chunkPath);
            }
            fclose($output);
            $output = null;

            File::deleteDirectory($tempDir);

            // Re-encode to a mobile-friendly size. Raw phone uploads are often
            // 10x larger than needed, which is what drives app data usage.
            // The encode itself lives in App\Jobs\CompressUploadedVideo; only
            // WHERE it runs is configurable, because that depends on whether a
            // queue worker exists on the server. See services.ffmpeg.mode.
            $this->scheduleCompression($finalPath);

            return response()->json([
                'message' => 'File merged successfully',
                'status' => 'merged',
                'filename' => $filename,
                'stored_filename' => $storedName,
                'stored_path' => "{$this->finalPath}{$storedName}",
                'compression' => ['applied' => false, 'reason' => 'queued'],
            ]);
        } catch (\Throwable $e) {
            // Never leave a half-written video behind. A PHP warning on a
            // missing chunk is raised as an exception, so this cleanup has to
            // live here rather than at the point of failure.
            if (is_resource($output)) {
                fclose($output);
            }
            if ($finalPath !== null && is_file($finalPath)) {
                @unlink($finalPath);
            }

            Log::error('Chunk merge failed', ['file' => $filename, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Upload could not be completed, please retry',
                'status' => 'merge_failed',
            ], 500);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($tempDir . '.lock');
        }
    }

    /**
     * Hand the encode off, however this server is able to run it.
     *
     *   queue — a worker takes it and the web worker is freed immediately.
     *           Correct for production, but silently does nothing unless
     *           "php artisan queue:work" is actually running.
     *   defer — after the response, still inside this PHP-FPM worker. Needs
     *           nothing installed, so it is the default, at the cost of
     *           holding one worker for the length of the encode.
     *   off   — serve exactly what was uploaded.
     *
     * The compression itself never throws into the upload: the job swallows
     * its own failures and keeps the original. This wrapper is defensive too,
     * so a misconfigured mode cannot cost someone their upload.
     */
    private function scheduleCompression(string $finalPath): void
    {
        $mode = strtolower((string) config('services.ffmpeg.mode', 'defer'));

        try {
            if ($mode === 'off') {
                return;
            }

            if ($mode === 'queue') {
                CompressUploadedVideo::dispatch($finalPath);
                return;
            }

            // 'defer', and anything unrecognised: the behaviour that works
            // without extra infrastructure is the safe fallback.
            defer(function () use ($finalPath) {
                // The client has its response; finish even if it disconnects.
                ignore_user_abort(true);
                set_time_limit(0);
                (new CompressUploadedVideo($finalPath))->handle();
            });
        } catch (\Throwable $e) {
            Log::error('Could not schedule video compression; original kept', [
                'file' => basename($finalPath),
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reduce a client-supplied name to a bare, safe filename.
     *
     * It is interpolated into a storage path, so directory separators and
     * traversal segments must not survive.
     */
    private function safeFilename($value): string
    {
        $name = basename(str_replace('\\', '/', (string) $value));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = ltrim($name, '.');

        return substr($name, 0, 180);
    }

    public function delete(Request $request)
    {
     

        $validator = Validator::make($request->all(), [
            'filename' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $filename = $request->input('filename');
        $path = 'public/uploads/final/' . $filename;

        if (Storage::exists($path)) {
            Storage::delete($path);
            return response()->json([
                'message' => 'File deleted successfully',
                'status' => 'deleted',
                'filename' => $filename
            ]);
        }

        return response()->json([
            'message' => 'File not found',
            'status' => 'not_found',
            'filename' => $filename
        ], 400);
    }
}

