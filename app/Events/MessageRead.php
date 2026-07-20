<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Acuse de lectura hasta un mensaje. Va al canal de la conversación (palomitas del
 * emisor) y al canal privado del propio lector (para bajar el badge en sus otros
 * dispositivos: leyó en el móvil, se actualiza la web).
 */
class MessageRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public int $lastReadMessageId,
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.' . $this->conversationId),
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id'       => $this->conversationId,
            'user_id'               => $this->userId,
            'last_read_message_id'  => $this->lastReadMessageId,
        ];
    }
}
