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
 * Regla: se avisa a todos los participantes activos menos el emisor y los que tengan
 * la conversación silenciada. NO se filtra por marca de lectura (esa es por usuario, no
 * por dispositivo; leer en la web no debe apagar el push del celular). Es el propio
 * móvil el que calla el aviso si tiene esa conversación abierta en primer plano.
 *
 * A diferencia de los eventos de broadcast del chat, esto SÍ va encolado: tarda
 * (llamada a Google) y que se demore unos segundos no rompe nada.
 */
class SendChatPush implements ShouldQueue
{
    use Queueable;

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

        // A quién se le avisa: todos los participantes activos menos el emisor y los
        // que tengan la conversación silenciada.
        //
        // NO se filtra por marca de lectura a propósito. Esa marca es POR USUARIO, no
        // por dispositivo: leer en la web la marcaba leída y el celular se quedaba sin
        // aviso, aunque el teléfono estuviera guardado. El celular es otro dispositivo
        // y debe avisar por su cuenta. Quien SÍ decide callar el aviso es el propio
        // móvil: si en ese momento tiene abierta esa conversación, no muestra la
        // notificación (lo ve en vivo). Así no hay doble aviso cuando de verdad se está
        // mirando el chat en el teléfono, pero leer en la web ya no apaga el push.
        $recipients = ConversationParticipant::where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->sender_id)
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
