<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCapturedMediaJob;
use App\Models\CaptureSession;
use App\Models\Property;
use App\Models\Property3dModel;
use App\Services\ThreeDModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CaptureSessionController extends Controller
{
    public function __construct(private ThreeDModelService $service) {}

    /**
     * Crear una sesión de captura (genera token QR).
     * POST /api/capture-sessions
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'nullable|integer|exists:properties,id',
            'source'      => 'nullable|string|in:qr_mobile,direct_mobile,upload',
        ]);

        $user = Auth::user();
        $propertyId = $request->input('property_id');

        // Verificar que la propiedad pertenece al usuario
        if ($propertyId) {
            $property = Property::findOrFail($propertyId);
            if ($property->user_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
            }
        }

        $source = $request->input('source', 'qr_mobile');
        $session = CaptureSession::generate($user->id, $propertyId, $source);

        $frontendUrl = config('app.frontend_url', rtrim(config('app.url'), '/'));
        $qrUrl = "{$frontendUrl}/capture/{$session->capture_token}";

        return response()->json([
            'success'    => true,
            'token'      => $session->capture_token,
            'qr_url'     => $qrUrl,
            'expires_at' => $session->expires_at->toISOString(),
            'session_id' => $session->id,
        ], 201);
    }

    /**
     * Obtener sesión por token (validación para la vista móvil del QR).
     * GET /api/capture-sessions/{token}
     */
    public function show(string $token): JsonResponse
    {
        $session = CaptureSession::where('capture_token', $token)->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            return response()->json(['success' => false, 'message' => 'Sesión expirada'], 410);
        }

        // Verificar que el usuario autenticado es el propietario de la sesión
        $user = Auth::user();
        if ($user && $session->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        return response()->json([
            'success'     => true,
            'session'     => [
                'token'       => $session->capture_token,
                'status'      => $session->status,
                'source'      => $session->source,
                'property_id' => $session->property_id,
                'expires_at'  => $session->expires_at->toISOString(),
            ],
        ]);
    }

    /**
     * Actualizar estado de la sesión (recording → uploading → completed).
     * PATCH /api/capture-sessions/{token}/status
     */
    public function updateStatus(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'status'       => 'required|string|in:recording,uploading,completed',
            'video_s3_url' => 'nullable|string|max:512',
        ]);

        $session = CaptureSession::where('capture_token', $token)->firstOrFail();

        if ($session->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Sesión expirada'], 410);
        }

        $user = Auth::user();
        if ($session->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        $updateData = ['status' => $request->input('status')];

        if ($request->filled('video_s3_url')) {
            $updateData['video_s3_url'] = $request->input('video_s3_url');
        }

        $session->update($updateData);

        return response()->json(['success' => true, 'status' => $session->status]);
    }

    /**
     * Generar URL prefirmada para subida directa a S3.
     * POST /api/capture-sessions/presigned-url
     */
    public function presignedUrl(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'filename'    => 'required|string|max:255',
        ]);

        $property = Property::findOrFail($request->input('property_id'));
        $user = Auth::user();

        if ($property->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        // Validar extensión del archivo
        $ext = strtolower(pathinfo($request->input('filename'), PATHINFO_EXTENSION));
        $allowedFormats = config('threed.video.allowed_formats', ['mp4', 'mov', 'webm']);

        if (!in_array($ext, $allowedFormats)) {
            return response()->json([
                'success' => false,
                'message' => "Formato no soportado. Use: " . implode(', ', $allowedFormats),
            ], 422);
        }

        try {
            $result = $this->service->generatePresignedUploadUrl($property, $request->input('filename'));

            return response()->json([
                'success' => true,
                ...$result,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Presigned URL generation failed', [
                'property_id' => $property->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar URL de subida. Use la subida directa.',
                'use_direct_upload' => true,
            ], 500);
        }
    }

    /**
     * Subida directa de video al almacenamiento (fallback cuando presigned URL falla).
     * POST /api/capture-sessions/upload-direct
     */
    public function uploadDirect(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'video'       => 'required|file|max:512000', // 500 MB
        ]);

        $property = Property::findOrFail($request->input('property_id'));
        $user = Auth::user();

        if ($property->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        $file = $request->file('video');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedFormats = config('threed.video.allowed_formats', ['mp4', 'mov', 'webm']);

        if (!in_array($ext, $allowedFormats)) {
            return response()->json([
                'success' => false,
                'message' => "Formato no soportado. Use: " . implode(', ', $allowedFormats),
            ], 422);
        }

        try {
            // ── Diagnóstico: info del archivo recibido ──
            $fileSize = $file->getSize();
            $fileMime = $file->getMimeType();
            $realPath = $file->getRealPath();

            Log::info('Upload-direct: file received', [
                'property_id'   => $property->id,
                'original_name' => $file->getClientOriginalName(),
                'extension'     => $ext,
                'size_bytes'    => $fileSize,
                'size_mb'       => round($fileSize / (1024 * 1024), 2),
                'mime_type'     => $fileMime,
                'real_path'     => $realPath,
                'is_valid'      => $file->isValid(),
            ]);

            // Validar que el archivo no esté vacío
            if (!$fileSize || $fileSize === 0) {
                Log::error('Upload-direct: file is empty', [
                    'property_id' => $property->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo de video está vacío.',
                ], 422);
            }

            // Generar key S3
            $folder = sprintf(
                '%s/%d',
                config('threed.s3.video_prefix', 'threed/videos'),
                $property->id,
            );
            $filename = Str::uuid() . '.' . $ext;
            $key = $folder . '/' . $filename;

            Log::info('Upload-direct: uploading to R2', [
                'key'    => $key,
                'bucket' => config('filesystems.disks.s3.bucket'),
                'endpoint' => config('filesystems.disks.s3.endpoint'),
            ]);

            // ── Subir al almacenamiento S3/R2 usando putFileAs (más confiable con R2) ──
            $disk = Storage::disk('s3');
            $storedPath = $disk->putFileAs($folder, $file, $filename);

            // Verificar que putFileAs retornó una ruta válida
            if (!$storedPath) {
                Log::error('Upload-direct: putFileAs returned false', [
                    'property_id' => $property->id,
                    'key'         => $key,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error al subir el video al almacenamiento. putFileAs falló.',
                ], 500);
            }

            Log::info('Upload-direct: putFileAs returned path', [
                'stored_path' => $storedPath,
            ]);

            // ── Verificar que el archivo realmente existe en R2 ──
            $exists = $disk->exists($storedPath);

            Log::info('Upload-direct: existence check', [
                'path'   => $storedPath,
                'exists' => $exists,
                'size'   => $exists ? $disk->size($storedPath) : null,
            ]);

            if (!$exists) {
                Log::error('Upload-direct: file not found after upload!', [
                    'property_id' => $property->id,
                    'stored_path' => $storedPath,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El video se envió pero no se encontró en el almacenamiento después de subir.',
                ], 500);
            }

            // ── Crear registro del modelo 3D ──
            $videoUrl = $disk->url($storedPath);
            $model = Property3dModel::updateOrCreate(
                ['property_id' => $property->id],
                [
                    'status'       => 'pending',
                    'video_s3_url' => $videoUrl,
                    'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                    'format'       => $ext,
                    'uploaded_at'  => now(),
                ]
            );

            // ── Despachar job de procesamiento (envía a Luma AI) ──
            $lumaApiKey = config('threed.luma.api_key');
            if ($lumaApiKey) {
                ProcessCapturedMediaJob::dispatch($model->id);
                Log::info('Upload-direct: dispatched ProcessCapturedMediaJob', [
                    'model_id' => $model->id,
                ]);
            } else {
                Log::info('Upload-direct: Luma API key not configured, skipping auto-processing', [
                    'model_id' => $model->id,
                ]);
            }

            Log::info('Upload-direct: SUCCESS', [
                'property_id' => $property->id,
                's3_key'      => $storedPath,
                'video_url'   => $videoUrl,
                'model_id'    => $model->id,
            ]);

            return response()->json([
                'success'   => true,
                's3_key'    => $storedPath,
                'video_url' => $videoUrl,
                'model_id'  => $model->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Upload-direct: EXCEPTION', [
                'property_id' => $property->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al subir el video: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirmar que la subida vía presigned URL se completó.
     * El frontend llama esto DESPUÉS de hacer PUT al presigned URL directamente.
     * POST /api/capture-sessions/confirm-upload
     */
    public function confirmPresignedUpload(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            's3_key'      => 'required|string|max:512',
            'model_id'    => 'required|integer|exists:property_3d_models,id',
        ]);

        $property = Property::findOrFail($request->input('property_id'));
        $user = Auth::user();

        if ($property->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        try {
            $s3Key = $request->input('s3_key');
            $disk = Storage::disk('s3');

            // Verificar que el archivo realmente existe en R2
            if (!$disk->exists($s3Key)) {
                Log::error('Confirm-presigned: file not found in R2', [
                    'property_id' => $property->id,
                    's3_key'      => $s3Key,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El video no se encontró en el almacenamiento. Intenta subir de nuevo.',
                ], 404);
            }

            $videoUrl = $disk->url($s3Key);
            $fileSize = $disk->size($s3Key);

            // Actualizar el modelo con la URL del video
            $model = Property3dModel::findOrFail($request->input('model_id'));
            $model->update([
                'status'       => 'pending',
                'video_s3_url' => $videoUrl,
                'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                'uploaded_at'  => now(),
            ]);

            // Despachar job de procesamiento (envía a Luma AI)
            $lumaApiKey = config('threed.luma.api_key');
            if ($lumaApiKey) {
                ProcessCapturedMediaJob::dispatch($model->id);
                Log::info('Confirm-presigned: dispatched ProcessCapturedMediaJob', [
                    'model_id' => $model->id,
                ]);
            }

            Log::info('Confirm-presigned: SUCCESS', [
                'property_id' => $property->id,
                'video_url'   => $videoUrl,
                'model_id'    => $model->id,
            ]);

            return response()->json([
                'success'   => true,
                'video_url' => $videoUrl,
                'model_id'  => $model->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Confirm-presigned: EXCEPTION', [
                'property_id' => $property->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar la subida: ' . $e->getMessage(),
            ], 500);
        }
    }
}
