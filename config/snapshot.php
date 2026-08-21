<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Respaldos portables (snapshot:export / snapshot:import)
    |--------------------------------------------------------------------------
    |
    | Un "snapshot" es un ZIP con TODA la instalación: la base de datos completa
    | (usuarios, roles, permisos, configuración y datos) más los archivos subidos
    | (fotos, planos, logos). Sirve para clonar producción en local o para mover la
    | instalación a otro servidor.
    |
    */

    // Carpeta con los binarios de PostgreSQL (pg_dump/psql). Vacío = se buscan solos
    // en las rutas típicas de Windows y Linux.
    'pg_bin' => env('PG_BIN', ''),

    // Dónde se guardan los ZIP generados (relativo al disco 'local').
    'path' => 'snapshots',

    // Carpetas de storage que viajan en el ZIP.
    //   app/public  → lo que sirve por URL: fotos de evidencia, imágenes de planos,
    //                 firmas, logos, archivos de contrato.
    //   app/private → lo que NO es público pero sí es dato: documentos de la base de
    //                 conocimiento (referenciados por ai_documents.file_path) y los
    //                 ZIP de hojas de servicio ya generados.
    // Si aparece un disco nuevo en config/filesystems.php, hay que darlo de alta aquí
    // o el clon nacerá incompleto.
    'media' => [
        'app/public',
        'app/private',
    ],

    /*
    | Al importar FUERA de producción se "sanea" el clon para que no hable con el
    | mundo real: sin SMTP, sin webhooks, sin integraciones ni canales de mensajería,
    | sin tokens de sesión ni de push. Es lo que evita que un ambiente de pruebas le
    | mande correos a los clientes de verdad.
    */
    'sanitize' => [
        // Claves de app_settings que se vacían (credenciales de correo del tenant).
        'settings_keys' => [
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
            'smtp_password', 'smtp_from_email', 'smtp_from_name',
        ],

        // Tablas que se apagan (columna => valor).
        'disable' => [
            'webhook_endpoints' => ['is_active' => false],
            'integrations'      => ['is_active' => false],
            'channels'          => ['is_active' => false, 'ai_enabled' => false],
        ],

        // Tablas que se vacían por completo.
        'truncate' => [
            'personal_access_tokens',   // sesiones de la instalación original
            'device_tokens',            // push a celulares reales
        ],

        // Contraseña que queda en TODOS los usuarios del clon.
        'password' => 'Local@2026',
    ],

    /*
    | Columnas donde quedaron guardadas URLs absolutas de archivos (fotos, firmas,
    | planos, logo). Al importar se reescribe el dominio de origen por el de esta
    | instalación: si no, el clon seguiría pidiéndole las imágenes al servidor
    | original —y sin acceso a él se vería sin fotos.
    */
    'url_columns' => [
        'app_settings'           => ['value'],
        'floor_plans'            => ['image_url'],
        'events'                 => ['images', 'field_values'],
        'maintenance_activities' => ['field_values'],
        'devices'                => ['custom_fields'],
        'event_comments'         => ['body'],
    ],

];
