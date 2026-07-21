<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push genérico a un conjunto de usuarios. Gemelo de SendChatPush pero sin lógica de
 * chat: el emisor ya decidió A QUIÉN (y filtró preferencias/actor); aquí solo se
 * resuelven los tokens y se envía por FCM/APNs vía PushSender.
 *
 * Encolado a propósito: hablar con Google/Apple tarda y no debe frenar la petición
 * (crear un evento, cambiar estado) que lo disparó.
 */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int,int>     $userIds
     * @param  array<string,string>  $data  payload para el deep-link en la app
     */
    public function __construct(
        public array $userIds,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
    }

    public function handle(PushSender $sender): void
    {
        // Sin ningún canal configurado no se manda nada: la app sigue igual, solo sin push.
        if (! $sender->isConfigured() || $this->userIds === []) {
            return;
        }

        $tokens = DeviceToken::whereIn('user_id', $this->userIds)->get();
        if ($tokens->isEmpty()) {
            return;
        }

        $dead = $sender->send($tokens, $this->title, $this->body, $this->data);

        // Tokens muertos (app desinstalada / token rotado): se borran o fallarían por siempre.
        if ($dead !== []) {
            DeviceToken::whereIn('token', $dead)->delete();
        }
    }
}
