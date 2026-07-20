<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use Pushok\AuthProvider\Token as ApnsToken;
use Pushok\Client;
use Pushok\Notification;
use Pushok\Payload;
use Pushok\Payload\Alert;

/**
 * Envío directo a APNs (iOS) con llave .p8, vía HTTP/2.
 *
 * No se pasa por Firebase porque `expo-notifications` entrega en iOS un token APNs
 * y FCM no acepta tokens APNs como destino (ahí el token APNs es *entrada* a
 * Firebase, no destino). Esta es la ruta que la propia documentación de Expo
 * describe para quien no usa el Expo Push Service.
 *
 * Sin llave configurada se comporta como no-op, igual que FcmSender.
 */
class ApnsSender
{
    /**
     * Códigos de APNs que significan "este token ya no sirve" (410 = el aparato ya
     * no tiene la app). Se distinguen de un fallo temporal: estos se borran, los
     * demás se reintentan.
     */
    private const DEAD_REASONS = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    public function isConfigured(): bool
    {
        $path = config('apns.key_path');

        return $path
            && is_readable($path)
            && config('apns.key_id')
            && config('apns.team_id')
            && config('apns.bundle_id');
    }

    /**
     * @param  array<int,string>     $tokens
     * @param  array<string,string>  $data
     * @return array<int,string>  tokens muertos (para borrar)
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->isConfigured() || $tokens === []) {
            return [];
        }

        $payload = Payload::create()
            ->setAlert(Alert::create()->setTitle($title)->setBody(mb_strimwidth($body, 0, 180, '…')))
            ->setSound('default')
            // `mutable-content` deja que la app enriquezca la notificación si algún
            // día se quieren imágenes en el aviso.
            ->setMutableContent(true);

        // El deep-link viaja como datos personalizados, igual que en `data` de FCM.
        foreach ($data as $key => $value) {
            $payload->setCustomValue($key, $value);
        }

        try {
            $auth = ApnsToken::create([
                'key_id'   => config('apns.key_id'),
                'team_id'  => config('apns.team_id'),
                'app_bundle_id' => config('apns.bundle_id'),
                'private_key_path' => config('apns.key_path'),
                'private_key_secret' => null,
            ]);

            $client = new Client($auth, isProductionEnv: (bool) config('apns.production'));

            foreach ($tokens as $token) {
                $client->addNotification(new Notification($payload, $token));
            }

            $responses = $client->push();
        } catch (\Throwable $e) {
            Log::warning('ApnsSender: fallo al enviar', ['error' => $e->getMessage()]);
            throw $e;   // que el Job reintente
        }

        $dead = [];
        foreach ($responses as $response) {
            // 410 = token dado de baja por Apple; 400 con razón de token inválido.
            if ($response->getStatusCode() === 410
                || in_array($response->getErrorReason(), self::DEAD_REASONS, true)) {
                $dead[] = $response->getDeviceToken();
                continue;
            }

            if ($response->getStatusCode() >= 400) {
                Log::warning('ApnsSender: rechazo de APNs', [
                    'status' => $response->getStatusCode(),
                    'reason' => $response->getErrorReason(),
                ]);
            }
        }

        return $dead;
    }
}
