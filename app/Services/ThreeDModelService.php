<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Property3dModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ThreeDModelService
{
    // ==================== S3 PRESIGNED URL ====================

    /**
     * Genera una URL prefirmada de S3 para subida directa desde el navegador.
     * Esto bypasea el límite de PHP para archivos grandes (hasta 500 MB).
     */
    public function generatePresignedUploadUrl(Property $property, string $filename): array
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $key = sprintf(
            '%s/%d/%s.%s',
            config('threed.s3.video_prefix'),
            $property->id,
            Str::uuid(),
            $ext
        );

        $disk = Storage::disk('s3');
        $client = $disk->getClient();
        $bucket = config('filesystems.disks.s3.bucket');
        $ttl = config('threed.presigned_url_ttl_minutes', 30);

        $cmd = $client->getCommand('PutObject', [
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ContentType' => $this->mimeFromExtension($ext),
        ]);

        $presignedUrl = (string) $client->createPresignedRequest($cmd, "+{$ttl} minutes")->getUri();

        // Crear o actualizar el registro del modelo 3D
        $model = Property3dModel::updateOrCreate(
            ['property_id' => $property->id],
            ['status' => 'pending']
        );

        return [
            'presigned_url' => $presignedUrl,
            's3_key'        => $key,
            'model_id'      => $model->id,
            'expires_in'    => $ttl * 60,
        ];
    }

    // ==================== LUMA AI ====================

    /**
     * Envía el video a Luma AI para procesamiento 3D.
     * Retorna el task_id de Luma.
     */
    public function submitToLuma(Property3dModel $model): string
    {
        $apiKey = config('threed.luma.api_key');
        $apiUrl = config('threed.luma.api_url');
        $webhookUrl = rtrim(config('app.url'), '/') . '/api/webhooks/luma';

        $response = Http::withHeaders([
            'Authorization' => "luma-api-key={$apiKey}",
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post("{$apiUrl}/captures", [
            'title'        => "Property #{$model->property_id}",
            'input_url'    => $model->video_s3_url,
            'callback_url' => $webhookUrl,
        ]);

        if (!$response->successful()) {
            $error = $response->body();
            Log::error('Luma AI submission failed', [
                'property_id' => $model->property_id,
                'status'      => $response->status(),
                'body'        => $error,
            ]);
            throw new \RuntimeException("Luma API error ({$response->status()}): {$error}");
        }

        $lumaTaskId = $response->json('uuid') ?? $response->json('id') ?? $response->json('slug');

        if (!$lumaTaskId) {
            throw new \RuntimeException('Luma API did not return a task identifier.');
        }

        $model->markAsProcessing($lumaTaskId);

        Log::info('Luma AI submission successful', [
            'property_id'  => $model->property_id,
            'luma_task_id' => $lumaTaskId,
        ]);

        return $lumaTaskId;
    }

    // ==================== WEBHOOK ====================

    /**
     * Procesa el webhook de Luma AI cuando el modelo está listo.
     *
     * NOTA: Luma AI NO provee firma/HMAC en los webhooks a la fecha (Mar 2026).
     * Ellos simplemente envían un POST con el objeto de generación como body.
     * Se usa validación por luma_task_id (debe existir en BD) como mecanismo
     * de autenticación implícita. Si Luma implementa firma en el futuro,
     * agregar validación aquí con el LUMA_WEBHOOK_SECRET.
     */
    public function handleWebhook(array $payload): void
    {
        $lumaTaskId = $payload['uuid'] ?? $payload['id'] ?? $payload['slug'] ?? null;
        $status = $payload['status'] ?? null;

        if (!$lumaTaskId) {
            Log::warning('Luma webhook: missing task identifier', $payload);
            return;
        }

        $model = Property3dModel::where('luma_task_id', $lumaTaskId)->first();

        if (!$model) {
            Log::warning("Luma webhook: unknown task {$lumaTaskId}");
            return;
        }

        if ($status === 'complete' || $status === 'completed') {
            $downloadUrl = $payload['artifacts']['mesh_glb']
                ?? $payload['result_url']
                ?? $payload['download_url']
                ?? null;

            if (!$downloadUrl) {
                $model->markAsFailed('Luma completed but no download URL found in webhook payload.');
                Log::error('Luma webhook: completed without download URL', $payload);
                return;
            }

            $this->downloadAndStoreModel($model, $downloadUrl);
        } elseif ($status === 'failed' || $status === 'error') {
            $error = $payload['failure_reason'] ?? $payload['error'] ?? 'Unknown Luma error';
            $model->markAsFailed($error);
            Log::error("Luma webhook: task {$lumaTaskId} failed", ['error' => $error]);
        }
    }

    // ==================== ESTADO ====================

    /**
     * Retorna el estado actual del modelo 3D de una propiedad.
     */
    public function getStatus(int $propertyId): ?array
    {
        $model = Property3dModel::where('property_id', $propertyId)->first();

        if (!$model) return null;

        return [
            'status'                => $model->status,
            'model_url'             => $model->isReady() ? $model->model_s3_url : null,
            'video_url'             => $model->video_s3_url,
            'retry_count'           => $model->retry_count,
            'last_error'            => $model->isFailed() ? $model->last_error : null,
            'uploaded_at'           => $model->uploaded_at?->toISOString(),
            'processing_started_at' => $model->processing_started_at?->toISOString(),
            'completed_at'          => $model->completed_at?->toISOString(),
        ];
    }

    // ==================== PRIVADOS ====================

    /**
     * Descarga el GLB de Luma y lo almacena en S3 propio.
     */
    private function downloadAndStoreModel(Property3dModel $model, string $downloadUrl): void
    {
        try {
            $response = Http::timeout(120)->get($downloadUrl);

            if (!$response->successful()) {
                $model->markAsFailed("Failed to download GLB: HTTP {$response->status()}");
                return;
            }

            $key = sprintf(
                '%s/%d/model-%s.glb',
                config('threed.s3.model_prefix'),
                $model->property_id,
                Str::random(8)
            );

            $disk = Storage::disk('s3');
            $putResult = $disk->put($key, $response->body());

            if (!$putResult) {
                $model->markAsFailed('Failed to store GLB in R2: put() returned false');
                Log::error('downloadAndStoreModel: put() returned false', [
                    'property_id' => $model->property_id,
                    'key'         => $key,
                ]);
                return;
            }

            // Verificar que el archivo existe en R2
            if (!$disk->exists($key)) {
                $model->markAsFailed('GLB file not found in R2 after upload');
                Log::error('downloadAndStoreModel: file not found after upload', [
                    'property_id' => $model->property_id,
                    'key'         => $key,
                ]);
                return;
            }

            $s3Url = $disk->url($key);
            $model->markAsReady($s3Url);

            Log::info("3D model stored successfully", [
                'property_id' => $model->property_id,
                'model_url'   => $s3Url,
            ]);
        } catch (\Throwable $e) {
            $model->markAsFailed("Download error: {$e->getMessage()}");
            Log::error("3D model download failed", [
                'property_id' => $model->property_id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function mimeFromExtension(string $ext): string
    {
        return match (strtolower($ext)) {
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }
}
