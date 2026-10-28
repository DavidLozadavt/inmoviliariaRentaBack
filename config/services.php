<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ✅ AGREGAR ESTO PARA RESEND
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Google Gemini AI (Voice Assistant)

    // Puente con la pasarela de NexiService, School y el ERP (cabecera X-Bridge-Token).
    // Se lee aqui y no con env() en los controladores: con config:cache env() devuelve null.
    'bridge' => [
        'token' => env('BRIDGE_SECRET_TOKEN', 'VirtualT_Bridge_Secret_2026'),
        'nexi_url' => env('NEXI_API_URL', 'http://127.0.0.1:8001'),
        'school_url' => env('SCHOOL_API_URL', 'http://127.0.0.1:8006'),
    ],

];
