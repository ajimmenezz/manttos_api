<?php

return [
    // Versión de la Graph API para las llamadas a Meta.
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    // Base de la Graph API.
    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),

    // Verify token del webhook: debe coincidir con el configurado en Meta.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
];
