<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Services\Capture\InboundHandler;

/**
 * Normaliza un update de Telegram (webhook o long-polling) y lo entrega al
 * pipeline de captación. Solo maneja mensajes de texto/caption; otros tipos se
 * responden pidiendo una descripción.
 */
class TelegramUpdateProcessor
{
    public function __construct(private InboundHandler $handler) {}

    public function process(Channel $channel, array $update): void
    {
        $msg = $update['message'] ?? $update['edited_message'] ?? null;
        if (! is_array($msg)) {
            return;
        }

        $chatId = (string) ($msg['chat']['id'] ?? '');
        if ($chatId === '') {
            return;
        }

        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        if ($text === '') {
            $text = '(envié un archivo o mensaje sin texto)';
        }

        $from = $msg['from'] ?? [];
        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $username = $from['username'] ?? null;
        $name = $name !== '' ? $name : $username;

        $externalMessageId = isset($msg['message_id']) ? "tg:{$chatId}:{$msg['message_id']}" : null;

        $this->handler->handle($channel, $chatId, $name, $text, $externalMessageId, $username);
    }
}
