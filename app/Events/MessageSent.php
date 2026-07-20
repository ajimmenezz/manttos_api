<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mensaje nuevo. Se emite al canal de la conversación (para pintarlo en el hilo) y
 * al canal privado de cada destinatario (para el badge global de no-leídos, aunque
 * no tenga la conversación abierta).
 *
 * ShouldBroadcastNow (NO ShouldBroadcast): encolarlo lo dejaría a merced del worker,
 * y hoy la cola se drena con un cron cada minuto — un chat que tarda un minuto en
 * entregar no es un chat. El costo es que el request espera la llamada HTTP a Reverb.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<int,int>  $recipientIds  destinatarios (participantes activos menos el emisor) */
    public function __construct(
        public Message $message,
        public array $recipientIds = [],
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('conversation.' . $this->message->conversation_id)];
        foreach ($this->recipientIds as $userId) {
            $channels[] = new PrivateChannel('user.' . $userId);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** Payload compacto: lo justo para pintar la burbuja sin pegarle a la API. */
    public function broadcastWith(): array
    {
        $m = $this->message;

        return [
            'id'              => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender_id'       => $m->sender_id,
            'sender_name'     => $m->sender?->name,
            'body'            => $m->body,
            'reply_to_id'     => $m->reply_to_id,
            'client_uuid'     => $m->client_uuid,
            'created_at'      => $m->created_at?->toISOString(),
            'attachments'     => $m->attachments->map(fn ($a) => [
                'id'        => $a->id,
                'url'       => $a->url,
                'kind'      => $a->kind,
                'mime'      => $a->mime,
                'thumb_url' => $a->thumb_url,
            ])->all(),
        ];
    }
}
