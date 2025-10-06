<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;


class VideoUploadController extends Controller
{
    protected $tempPath = 'uploads/temp/';
    protected $finalPath = 'uploads/videos/';

    public function uploadChunk(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file',
            'filename' => 'required|string',
            'chunkIndex' => 'required|numeric',
            'totalChunks' => 'required|numeric',
        ]);

        $chunk = $request->file('chunk');
        $filename = $request->input('filename');
        $chunkIndex = $request->input('chunkIndex');

        $tempDir = storage_path('app/' . $this->tempPath . $filename);
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0777, true);
        }

        $chunk->move($tempDir, "part-{$chunkIndex}");

        return response()->json(['message' => "Chunk {$chunkIndex} uploaded"]);
    }

    public function mergeChunks(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'totalChunks' => 'required|numeric',
        ]);

        $filename = $request->input('filename');
        $totalChunks = $request->input('totalChunks');
        $tempDir = storage_path('app/' . $this->tempPath . $filename);
        $finalDir = storage_path('app/' . $this->finalPath);

        if (!File::exists($tempDir)) {
            return response()->json(['message' => 'Temp chunks not found'], 404);
        }

        if (!File::exists($finalDir)) {
            File::makeDirectory($finalDir, 0777, true);
        }

        $finalPath = $finalDir . '/' . $filename;
        $output = fopen($finalPath, 'ab');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $tempDir . "/part-{$i}";
            if (!File::exists($chunkFile)) {
                fclose($output);
                return response()->json(['message' => "Missing chunk {$i}"], 400);
            }

            $chunkData = fopen($chunkFile, 'rb');
            stream_copy_to_stream($chunkData, $output);
            fclose($chunkData);
            File::delete($chunkFile);
        }

        fclose($output);
        File::deleteDirectory($tempDir);

        return response()->json(['message' => '✅ File merged successfully', 'filename' => $filename]);
    }
}
