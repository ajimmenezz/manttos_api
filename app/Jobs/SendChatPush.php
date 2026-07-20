<?php

namespace App\Jobs;

use App\Models\ConversationParticipant;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push de un mensaje de chat (fase 2). El envío en sí lo hace PushSender (FCM en
 * Android, APNs en iOS); aquí solo se decide A QUIÉN se le avisa.
 *
 * Regla clave: NO se avisa a quien ya vio el mensaje. En vez de inventar un sistema
 * de presencia, se aprovecha la marca de agua que el chat ya lleva: el job se despacha
 * con unos segundos de retraso y, al ejecutarse, salta a los participantes cuyo
 * `last_read_message_id` ya alcanzó este mensaje (o sea, lo tenían abierto y el
 * WebSocket ya se los mostró). Así el push solo llega a quien de verdad no lo vio.
 *
 * A diferencia de los eventos de broadcast del chat, esto SÍ va encolado: tarda
 * (llamada a Google) y que se demore unos segundos no rompe nada.
 */
class SendChatPush implements ShouldQueue
{
    use Queueable;

    /** Margen para que el acuse de lectura del WebSocket llegue antes que el push. */
    public const READ_GRACE_SECONDS = 5;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
    }

    public function handle(PushSender $sender): void
    {
        // Sin ningún canal configurado el módulo simplemente no manda push: el chat
        // sigue funcionando. Es lo que permite desplegar antes de tener Firebase.
        if (! $sender->isConfigured()) {
            return;
        }

        $message = Message::with(['conversation', 'sender'])->find($this->messageId);
        if (! $message || $message->trashed() || ! $message->conversation) {
            return;
        }

        $conversation = $message->conversation;

        $recipients = ConversationParticipant::where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->sender_id)
            // Ya lo leyó (lo tenía abierto): el WebSocket hizo el trabajo.
            ->where(function ($q) use ($message) {
                $q->whereNull('last_read_message_id')
                  ->orWhere('last_read_message_id', '<', $message->id);
            })
            // Silenciada.
            ->where(function ($q) {
                $q->whereNull('muted_until')->orWhere('muted_until', '<', now());
            })
            ->pluck('user_id');

        if ($recipients->isEmpty()) {
            return;
        }

        $tokens = DeviceToken::whereIn('user_id', $recipients)->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $title = $conversation->isGroup()
            ? $conversation->name
            : ($message->sender?->name ?? 'Nuevo mensaje');

        $body = $message->body
            ?: ($message->attachments()->exists() ? '📎 Imagen' : 'Nuevo mensaje');

        // En grupo hay que decir quién habló; en el directo el título ya es la persona.
        if ($conversation->isGroup() && $message->sender?->name) {
            $body = $message->sender->name . ': ' . $body;
        }

        // `data` es lo que la app usa para el deep-link al hilo correcto.
        $dead = $sender->send($tokens, $title, $body, [
            'type'            => 'chat_message',
            'conversation_id' => (string) $conversation->id,
            'message_id'      => (string) $message->id,
        ]);

        // Tokens muertos (app desinstalada / token rotado): se borran o volverían a
        // fallar en cada mensaje, para siempre.
        if ($dead !== []) {
            DeviceToken::whereIn('token', $dead)->delete();
        }
    }
}
