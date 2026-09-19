<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envío por Web Push estándar (VAPID) a la PWA: iPhone sin App Store y cualquier
 * navegador que la tenga instalada.
 *
 * El "token" que registra la PWA es la suscripción de Web Push COMPLETA en JSON
 * (endpoint + llaves p256dh/auth): con las llaves se cifra el contenido para ese
 * navegador y nadie más puede leerlo. El endpoint es del servicio del navegador
 * (web.push.apple.com, fcm.googleapis.com…), no nuestro.
 *
 * Sin llaves VAPID configuradas se comporta como no-op, igual que FcmSender.
 */
class WebPushSender
{
    public function isConfigured(): bool
    {
        return (bool) (config('webpush.public_key') && config('webpush.private_key'));
    }

    /**
     * @param  array<int,string>     $tokens  suscripciones en JSON
     * @param  array<string,string>  $data
     * @return array<int,string>  tokens muertos (para borrar)
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->isConfigured() || $tokens === []) {
            return [];
        }

        $dead = [];
        $byEndpoint = [];

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('webpush.subject'),
                    'publicKey'  => config('webpush.public_key'),
                    'privateKey' => config('webpush.private_key'),
                ],
            ], ['TTL' => 86400, 'urgency' => 'high']);

            // Lo lee el service worker de la PWA (evento `push`): mismo `data` que el
            // push nativo, para que el toque lleve a la misma pantalla.
            $payload = json_encode([
                'title' => $title,
                'body'  => mb_strimwidth($body, 0, 180, '…'),
                'data'  => $data,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($tokens as $token) {
                $sub = json_decode($token, true);
                if (! is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
                    // Un token que no es una suscripción no va a servir nunca.
                    $dead[] = $token;
                    continue;
                }
                $byEndpoint[$sub['endpoint']] = $token;
                $webPush->queueNotification(Subscription::create($sub), $payload);
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }
                $endpoint = $report->getEndpoint();
                // 404/410: el navegador dio de baja la suscripción (app desinstalada,
                // permiso retirado). Se borra; cualquier otro fallo sólo se registra.
                if ($report->isSubscriptionExpired()) {
                    if (isset($byEndpoint[$endpoint])) {
                        $dead[] = $byEndpoint[$endpoint];
                    }
                    continue;
                }
                Log::warning('WebPushSender: rechazo del servicio de push', [
                    'host'   => parse_url($endpoint, PHP_URL_HOST),
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WebPushSender: fallo al enviar', ['error' => $e->getMessage()]);
            throw $e;   // que el Job reintente
        }

        return array_values(array_unique($dead));
    }
}
