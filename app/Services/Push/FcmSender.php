<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * Envío por FCM HTTP v1 (Android) con la service account de Firebase.
 *
 * Sin credenciales configuradas se comporta como no-op: el chat debe poder
 * desplegarse y funcionar antes de que Firebase esté listo.
 */
class FcmSender
{
    public function isConfigured(): bool
    {
        $project = config('firebase.default', 'app');

        return (bool) config("firebase.projects.{$project}.credentials");
    }

    /**
     * @param  array<int,string>     $tokens
     * @param  array<string,string>  $data
     * @return array<int,string>  tokens inválidos o desconocidos (para borrar)
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if (! $this->isConfigured() || $tokens === []) {
            return [];
        }

        // Canal Android por categoría: el usuario controla sonido/importancia de "Chat"
        // y "Eventos" por separado desde Ajustes del sistema. Si el canal no existe en el
        // dispositivo, Android usa el default (no falla). El chat es el único type de chat.
        $channel = ($data['type'] ?? null) === 'chat_message' ? 'chat' : 'events';

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, mb_strimwidth($body, 0, 180, '…')))
            ->withData($data)
            ->withAndroidConfig(AndroidConfig::fromArray([
                'notification' => ['channel_id' => $channel],
            ]));

        try {
            // FCM acepta máximo 500 destinatarios por multicast.
            $dead = [];
            foreach (array_chunk($tokens, 500) as $chunk) {
                $report = app('firebase.messaging')->sendMulticast($message, $chunk);
                $dead = array_merge($dead, $report->invalidTokens(), $report->unknownTokens());
            }

            return $dead;
        } catch (\Throwable $e) {
            Log::warning('FcmSender: fallo al enviar', ['error' => $e->getMessage()]);
            throw $e;   // que el Job reintente: puede ser un corte de red
        }
    }
}
