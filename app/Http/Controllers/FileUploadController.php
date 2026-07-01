<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class FileUploadController extends Controller
{
    /**
     * Upload a file to Cloudflare R2 (configured as s3 disk).
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:10240', // Max 10MB
        ]);

        try {
            $file = $request->file('file');
            $disk = config('filesystems.default', 's3');
            
            // Store the file in the 'uploads' folder within the bucket
            // Path will be something like: uploads/abc12345.jpg
            $path = $file->store('uploads', $disk);

            // Get the persistent URL
            $url = Storage::disk($disk)->url($path);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully to persistent storage.',
                'path'    => $path,
                'url'     => $url,
                'disk'    => $disk
            ], 201);

        } catch (\Throwable $e) {
            Log::error('R2 Upload Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
