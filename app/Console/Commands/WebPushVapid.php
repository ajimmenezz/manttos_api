<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Genera el par de llaves VAPID del push web (PWA). Se corre UNA vez por servidor
 * y el resultado va al .env; volver a generarlas invalida las suscripciones vigentes.
 */
class WebPushVapid extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Genera las llaves VAPID del push web (PWA) para pegarlas en el .env';

    public function handle(): int
    {
        if (config('webpush.public_key')) {
            $this->warn('Ya hay llaves VAPID en el .env. Reemplazarlas obliga a cada teléfono a volver a suscribirse.');
        }

        $keys = VAPID::createVapidKeys();

        $this->line('Agrega esto al .env del API y luego `php artisan config:clear`:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:soporte@siccob.com.mx');

        return self::SUCCESS;
    }
}
