<?php

namespace App\Jobs;

use App\Models\Property3dModel;
use App\Services\ThreeDModelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCapturedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de reintentos (backoff exponencial: 60s, 300s, 900s).
     */
    public int $tries;
    public int $timeout;
    public array $backoff;

    public function __construct(public int $modelId)
    {
        $this->queue   = config('threed.job.queue', 'threed-processing');
        $this->tries   = config('threed.job.max_retries', 3);
        $this->timeout = config('threed.job.timeout', 600);
        $this->backoff = config('threed.job.backoff', [60, 300, 900]);
    }

    public function handle(ThreeDModelService $service): void
    {
        $model = Property3dModel::find($this->modelId);

        if (!$model) {
            Log::warning("ProcessCapturedMediaJob: model #{$this->modelId} not found, skipping.");
            return;
        }

        if ($model->isReady()) {
            Log::info("ProcessCapturedMediaJob: model #{$this->modelId} already ready, skipping.");
            return;
        }

        if (!$model->video_s3_url) {
            $model->markAsFailed('No video URL available for processing.');
            Log::error("ProcessCapturedMediaJob: model #{$this->modelId} has no video URL.");
            return;
        }

        try {
            Log::info("ProcessCapturedMediaJob: submitting model #{$this->modelId} to Luma AI.");
            $service->submitToLuma($model);
        } catch (\Throwable $e) {
            Log::error("ProcessCapturedMediaJob: failed for model #{$this->modelId}", [
                'error'       => $e->getMessage(),
                'retry_count' => $model->retry_count,
            ]);
            $model->markAsFailed($e->getMessage());
            throw $e; // Re-throw para que Laravel aplique el backoff y reintente
        }
    }

    /**
     * Cuando se agotan todos los reintentos.
     */
    public function failed(\Throwable $exception): void
    {
        $model = Property3dModel::find($this->modelId);

        if ($model) {
            $model->markAsFailed("Job permanently failed: {$exception->getMessage()}");
            Log::error("ProcessCapturedMediaJob: permanently failed for model #{$this->modelId}", [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
