<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Property3dModel extends Model
{
    protected $table = 'property_3d_models';

    protected $fillable = [
        'property_id', 'status', 'video_s3_url', 'model_s3_url',
        'luma_task_id', 'file_size_mb', 'format', 'dimensions',
        'retry_count', 'last_error', 'uploaded_at',
        'processing_started_at', 'completed_at', 'failed_at',
    ];

    protected $casts = [
        'dimensions'           => 'array',
        'file_size_mb'         => 'decimal:2',
        'retry_count'          => 'integer',
        'uploaded_at'          => 'datetime',
        'processing_started_at'=> 'datetime',
        'completed_at'         => 'datetime',
        'failed_at'            => 'datetime',
    ];

    // ==================== RELACIONES ====================

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    // ==================== HELPERS ====================

    public function isReady(): bool      { return $this->status === 'ready'; }
    public function isProcessing(): bool  { return $this->status === 'processing'; }
    public function isFailed(): bool      { return $this->status === 'failed'; }

    public function markAsUploading(string $videoUrl, float $sizeMb, string $format): void
    {
        $this->update([
            'status'        => 'uploading',
            'video_s3_url'  => $videoUrl,
            'file_size_mb'  => $sizeMb,
            'format'        => $format,
            'uploaded_at'   => now(),
        ]);
    }

    public function markAsProcessing(string $lumaTaskId): void
    {
        $this->update([
            'status'                => 'processing',
            'luma_task_id'          => $lumaTaskId,
            'processing_started_at' => now(),
        ]);
    }

    public function markAsReady(string $modelUrl): void
    {
        $this->update([
            'status'       => 'ready',
            'model_s3_url' => $modelUrl,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status'      => 'failed',
            'last_error'  => $error,
            'retry_count' => $this->retry_count + 1,
            'failed_at'   => now(),
        ]);
    }

    public function resetForRetry(): void
    {
        $this->update([
            'status'     => 'pending',
            'last_error' => null,
            'luma_task_id' => null,
        ]);
    }
}
