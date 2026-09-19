<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID) — avisos de la versión web / PWA
    |--------------------------------------------------------------------------
    |
    | La PWA (sobre todo en iPhone, donde no hay APK) recibe los avisos por el
    | estándar Web Push, sin Firebase: el servidor firma cada envío con su par de
    | llaves VAPID y lo entrega al servicio del navegador (Apple, Google, Mozilla).
    |
    | Las llaves se generan UNA vez con `php artisan webpush:vapid` y se guardan en
    | el .env. Si se cambian, las suscripciones existentes dejan de servir (cada
    | teléfono se vuelve a suscribir solo al abrir la app).
    |
    */

    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    // Contacto que ven los servicios de push si algo va mal. mailto: o https://.
    'subject' => env('VAPID_SUBJECT', 'mailto:soporte@siccob.com.mx'),

];
