<?php

namespace App\Services\Push;

use App\Models\DeviceToken;

/**
 * Envío de push, separado del Job que decide A QUIÉN notificar.
 *
 * Existe porque iOS y Android NO comparten camino: el token que `expo-notifications`
 * entrega en iOS es un token **APNs**, y FCM no acepta tokens APNs como destino (en
 * iOS el token APNs es ENTRADA a Firebase, nunca destino). Así que Android sale por
 * FCM HTTP v1 y iOS saldrá por APNs directo. El Job no tiene por qué enterarse: pide
 * "manda esto a estos tokens" y aquí se reparte por `provider`.
 *
 * @see FcmSender   Android
 * @see ApnsSender  iOS
 */
class PushSender
{
    public function __construct(private FcmSender $fcm, private ApnsSender $apns)
    {
    }

    /**
     * Manda la notificación y devuelve los tokens MUERTOS, para que quien llame los
     * borre. Nunca lanza por un token inválido: eso es operación normal, no un fallo.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceToken>  $tokens
     * @param  array<string,string>  $data  payload para el deep-link
     * @return array<int,string>  tokens a eliminar
     */
    public function send($tokens, string $title, string $body, array $data = []): array
    {
        $byProvider = $tokens->groupBy('provider');

        $dead = [];

        if ($fcmTokens = $byProvider->get('fcm')) {
            $dead = array_merge($dead, $this->fcm->send(
                $fcmTokens->pluck('token')->all(), $title, $body, $data
            ));
        }

        if ($apnsTokens = $byProvider->get('apns')) {
            $dead = array_merge($dead, $this->apns->send(
                $apnsTokens->pluck('token')->all(), $title, $body, $data
            ));
        }

        return $dead;
    }

    /**
     * ¿Hay al menos UN canal configurado? Si no, el Job ni se molesta. Basta con uno:
     * tener solo Android listo no debe impedir que Android reciba.
     */
    public function isConfigured(): bool
    {
        return $this->fcm->isConfigured() || $this->apns->isConfigured();
    }
}
