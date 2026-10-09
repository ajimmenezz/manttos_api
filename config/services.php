<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    // Almacenamiento (módulo «Almacenamiento»): una unidad compartida de Google Workspace
    // a la que solo entra una cuenta de servicio como «Gestor de contenido». La llave JSON
    // vive fuera del repo (storage/app/private está ignorado).
    'google_drive' => [
        'credentials' => env('GOOGLE_DRIVE_CREDENTIALS', storage_path('app/private/google/drive-sa.json')),
        'drive_id' => env('GOOGLE_DRIVE_ID'),
        // Opcional: carpeta dentro de la unidad que hace de raíz (desarrollo usa una propia).
        'root_id' => env('GOOGLE_DRIVE_ROOT_ID'),
        'max_upload_mb' => (int) env('GOOGLE_DRIVE_MAX_UPLOAD_MB', 5120),
    ],

];
