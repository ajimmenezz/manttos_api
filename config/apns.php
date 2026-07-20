<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Apple Push Notification service (APNs)
    |--------------------------------------------------------------------------
    |
    | iOS NO sale por Firebase: el token que entrega expo-notifications en iOS es
    | un token APNs, y FCM no acepta tokens APNs como destino. Por eso se envía
    | directo a Apple con una llave de autenticación .p8.
    |
    | La llave se descarga UNA SOLA VEZ del portal de Apple y NO va en el repo:
    | se deja en el servidor y se apunta con APNS_KEY_PATH.
    |
    */

    // Ruta absoluta al archivo AuthKey_XXXXXXXXXX.p8
    'key_path' => env('APNS_KEY_PATH'),

    // Key ID de la llave (10 caracteres, aparece al crearla).
    'key_id' => env('APNS_KEY_ID'),

    // Team ID de la cuenta de desarrollador (10 caracteres).
    'team_id' => env('APNS_TEAM_ID'),

    // Bundle identifier de la app. Debe ser IDÉNTICO al del build.
    'bundle_id' => env('APNS_BUNDLE_ID'),

    /*
    | Ambiente. Es la fuente de error más común: los builds de TestFlight y App
    | Store llevan aps-environment "production", y sus tokens SOLO funcionan
    | contra APNs producción. Un build corrido desde Xcode va contra sandbox.
    */
    'production' => (bool) env('APNS_PRODUCTION', true),

];
