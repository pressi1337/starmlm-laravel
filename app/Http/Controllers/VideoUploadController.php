<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;


class VideoUploadController extends Controller
{
    private $tempPath = 'uploads/tmp/';
    private $finalPath = 'public/uploads/final/';

    public function upload(Request $request)
    {
        ini_set('max_execution_time', 0);
        ini_set('upload_max_filesize', '2000M');
        ini_set('post_max_size', '2000M');
        ini_set('memory_limit', '2000M');

        $validator = Validator::make($request->all(), [
            'videofile' => 'required|file',
            'filename' => 'required|string',
            'chunkIndex' => 'required|integer|min:0',
            'totalChunks' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $chunk = $request->file('videofile');
        $filename = $request->input('filename');
        $chunkIndex = (int) $request->input('chunkIndex');
        $totalChunks = (int) $request->input('totalChunks');

        $tempDir = storage_path("app/{$this->tempPath}{$filename}");
        $finalDir = storage_path("app/{$this->finalPath}");

        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0777, true);
        }

        // Save current chunk
        $chunk->move($tempDir, "part-{$chunkIndex}");

        // Fast progress check: count parts in temp dir to avoid repeated filesystem stat scans
        $partFiles = glob("{$tempDir}/part-*") ?: [];
        $uploadedCount = count($partFiles);

        if ($uploadedCount === $totalChunks) {
            // Verify all sequential parts from 0 to totalChunks - 1 are present
            $allPartsPresent = true;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (!file_exists("{$tempDir}/part-{$i}")) {
                    $allPartsPresent = false;
                    break;
                }
            }

            if ($allPartsPresent) {
                if (!File::exists($finalDir)) {
                    File::makeDirectory($finalDir, 0777, true);
                }

                $storedName = Str::uuid() . '_' . $filename; // safe unique name
                $finalPath = "{$finalDir}/{$storedName}";

                $output = fopen($finalPath, 'ab');
                for ($i = 0; $i < $totalChunks; $i++) {
                    $chunkPath = "{$tempDir}/part-{$i}";
                    $chunkStream = fopen($chunkPath, 'rb');
                    stream_copy_to_stream($chunkStream, $output);
                    fclose($chunkStream);
                    File::delete($chunkPath);
                }
                fclose($output);

                File::deleteDirectory($tempDir);

                // Re-encode to a mobile-friendly size if enabled.
                // Deferred until after the response is flushed to client:
                // fastcgi_finish_request ensures the client is not blocked.
                defer(function () use ($finalPath) {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    ignore_user_abort(true);
                    set_time_limit(0);
                    $this->compressInPlace($finalPath);
                });

                return response()->json([
                    'message' => 'File merged successfully',
                    'status' => 'merged',
                    'filename' => $filename,
                    'stored_filename' => $storedName,
                    'stored_path' => "{$this->finalPath}{$storedName}",
                    'compression' => ['applied' => false, 'reason' => 'queued'],
                ]);
            }
        }

        $uploadedChunks = array_map(function ($path) {
            return (int) substr(basename($path), 5); // extracts index from 'part-X'
        }, $partFiles);

        return response()->json([
            'message' => 'Chunk uploaded',
            'status' => 'chunk_uploaded',
            'uploadedChunks' => $uploadedChunks,
            'filename' => $filename,
        ]);
    }

    /**
     * Re-encode a just-merged video in place (720p H.264, AAC, faststart).
     *
     * Deliberately defensive — an upload must never fail because of this:
     *   • no ffmpeg on the server  -> keep the original, report why;
     *   • encode fails / output looks broken -> keep the original;
     *   • result isn't smaller (already compressed) -> keep the original.
     * The filename never changes, so nothing downstream has to know.
     */
    private function compressInPlace(string $path): array
    {
        $result = [
            'applied'     => false,
            'reason'      => null,
            'original_mb' => null,
            'final_mb'    => null,
        ];

        if (!config('services.ffmpeg.enabled', true)) {
            $result['reason'] = 'disabled';
            return $result;
        }

        try {
            $videoExt = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp'];
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $videoExt, true)) {
                $result['reason'] = 'not_a_video';
                return $result;
            }

            $originalSize = @filesize($path) ?: 0;
            if ($originalSize <= 0) {
                $result['reason'] = 'empty_file';
                return $result;
            }
            $result['original_mb'] = round($originalSize / 1048576, 1);

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
                $result['reason'] = 'ffmpeg_not_installed';
                return $result;
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
                // "fast" keeps the admin's upload wait reasonable; the size
                // difference vs "medium" is only a few percent.
                '-preset', 'fast',
                '-c:a', 'aac',
                '-b:a', '96k',
                // Index at the front so playback starts without fetching the
                // whole file — a large part of the data saving.
                '-movflags', '+faststart',
                $tmp,
            ]);
            $process->setTimeout(7200);
            $process->run();

            $newSize = is_file($tmp) ? (@filesize($tmp) ?: 0) : 0;

            if (!$process->isSuccessful() || $newSize < 10240) {
                @unlink($tmp);
                Log::warning('Video compression failed; original kept', [
                    'file'  => basename($path),
                    'error' => substr($process->getErrorOutput(), -500),
                ]);
                $result['reason'] = 'encode_failed';
                return $result;
            }

            if ($newSize >= $originalSize) {
                @unlink($tmp);
                $result['reason'] = 'already_optimised';
                $result['final_mb'] = $result['original_mb'];
                return $result;
            }

            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                $result['reason'] = 'replace_failed';
                return $result;
            }

            $result['applied'] = true;
            $result['final_mb'] = round($newSize / 1048576, 1);
            $result['reason'] = 'compressed';

            Log::info('Video compressed on upload', [
                'file' => basename($path),
                'from' => $result['original_mb'] . ' MB',
                'to'   => $result['final_mb'] . ' MB',
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Video compression error; original kept', [
                'file'  => basename($path),
                'error' => $e->getMessage(),
            ]);
            $result['reason'] = 'error';
            return $result;
        }
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

