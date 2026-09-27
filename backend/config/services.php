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

    // Login com Google (Laravel Socialite). Sem client_id/client_secret, o
    // botão "Entrar com Google" fica oculto no front-end (ver
    // GET /api/auth/google/status) e as rotas de redirect/callback
    // respondem 503 em vez de quebrar.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Assinatura eletrônica (Clicksign API 3.0). O token é lido SOMENTE
    // daqui, via config('services.clicksign.access_token') — nunca direto do
    // env() em código de aplicação (o env() deixa de funcionar depois de
    // `php artisan config:cache`). Ver App\Services\ClicksignService.
    'clicksign' => [
        'base_url' => env('CLICKSIGN_BASE_URL', 'https://sandbox.clicksign.com/api/v3'),
        'access_token' => env('CLICKSIGN_ACCESS_TOKEN'),
        'timeout' => (int) env('CLICKSIGN_TIMEOUT', 15),
        // Secret HMAC do webhook, gerado pela Clicksign ao cadastrar a URL.
        // Sem ele, POST /api/webhooks/clicksign recusa tudo (503).
        'webhook_secret' => env('CLICKSIGN_WEBHOOK_SECRET'),
    ],

    // Conversão DOCX → PDF (LibreOffice headless) — ver
    // App\Services\DocumentConversionService. `binary` é o caminho completo
    // do soffice (Windows: ...\LibreOffice\program\soffice.exe; Linux:
    // /usr/bin/soffice) ou só o nome, se estiver no PATH. Vazio = procura
    // "soffice" no PATH; não encontrado = upload de Word desativado (PDF
    // continua funcionando).
    'document_converter' => [
        'binary' => env('DOCUMENT_CONVERTER_BINARY'),
        'timeout' => (int) env('DOCUMENT_CONVERTER_TIMEOUT', 90),
        'temp_path' => env('DOCUMENT_CONVERTER_TEMP_PATH'),
    ],

];
