<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Luma AI API
    |--------------------------------------------------------------------------
    */
    'luma' => [
        'api_key'         => env('LUMA_API_KEY'),
        'api_url'         => env('LUMA_API_URL', 'https://webapp.engineeringlumalabs.com/api/v3'),
        'webhook_secret'  => env('LUMA_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restricciones de video
    |--------------------------------------------------------------------------
    */
    'video' => [
        'max_size_mb'       => (int) env('THREED_VIDEO_MAX_SIZE_MB', 500),
        'min_duration_sec'  => (int) env('THREED_VIDEO_MIN_DURATION_SEC', 15),
        'max_duration_sec'  => (int) env('THREED_VIDEO_MAX_DURATION_SEC', 180),
        'allowed_formats'   => ['mp4', 'mov', 'webm'],
        'min_resolution'    => 720,
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Paths
    |--------------------------------------------------------------------------
    */
    's3' => [
        'video_prefix' => 'threed/videos',
        'model_prefix' => 'threed/models',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL prefirmada
    |--------------------------------------------------------------------------
    */
    'presigned_url_ttl_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Job
    |--------------------------------------------------------------------------
    */
    'job' => [
        'queue'       => 'threed-processing',
        'max_retries' => 3,
        'timeout'     => 600,
        'backoff'     => [60, 300, 900], // segundos de espera entre reintentos
    ],
];
