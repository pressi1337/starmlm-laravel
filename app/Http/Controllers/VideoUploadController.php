<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


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

        // Check progress
        $uploadedChunks = collect(range(0, $totalChunks - 1))
            ->filter(fn($i) => File::exists("{$tempDir}/part-{$i}"))
            ->values();

        if ($uploadedChunks->count() === $totalChunks) {
            if (!File::exists($finalDir)) {
                File::makeDirectory($finalDir, 0777, true);
            }

            $storedName = Str::uuid() . '-' . $filename; // safe unique name
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

            return response()->json([
                'message' => 'File merged successfully',
                'status' => 'merged',
                'filename' => $filename,
                'stored_filename' => $storedName,
                'stored_path' => "{$this->finalPath}{$storedName}",
            ]);
        }

        return response()->json([
            'message' => 'Chunk uploaded',
            'status' => 'chunk_uploaded',
            'uploadedChunks' => $uploadedChunks,
            'filename' => $filename,
        ]);
    }
}

