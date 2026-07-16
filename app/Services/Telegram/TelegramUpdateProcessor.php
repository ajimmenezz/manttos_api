<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Services\Capture\InboundHandler;

/**
 * Normaliza un update de Telegram (webhook o long-polling) y lo entrega al
 * pipeline de captación. Maneja texto/caption y FOTOS (photo/document imagen):
 * descarga la media y la adjunta para que el agente la vea y se ate al evento.
 */
class TelegramUpdateProcessor
{
    public function __construct(
        private InboundHandler $handler,
        private TelegramClient $telegram,
    ) {}

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

        $imageUrls = $this->extractImages($channel, $msg);

        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        if ($text === '') {
            $text = $imageUrls ? '(envié una foto)' : '(envié un archivo o mensaje sin texto)';
        }

        $from = $msg['from'] ?? [];
        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $username = $from['username'] ?? null;
        $name = $name !== '' ? $name : $username;

        $externalMessageId = isset($msg['message_id']) ? "tg:{$chatId}:{$msg['message_id']}" : null;

        $this->handler->handle($channel, $chatId, $name, $text, $externalMessageId, $username, null, $imageUrls);
    }

    /**
     * Extrae y descarga las imágenes del mensaje: la foto de mayor resolución (última
     * del arreglo `photo`) y/o un `document` con mime image/*.
     *
     * @return array<int,string>
     */
    private function extractImages(Channel $channel, array $msg): array
    {
        $urls = [];

        $photos = $msg['photo'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $largest = $photos[array_key_last($photos)] ?? null; // Telegram las ordena de menor a mayor
            if (is_array($largest) && ! empty($largest['file_id'])) {
                if ($url = $this->telegram->downloadFile($channel, (string) $largest['file_id'])) {
                    $urls[] = $url;
                }
            }
        }

        $doc = $msg['document'] ?? null;
        if (is_array($doc) && ! empty($doc['file_id']) && str_starts_with((string) ($doc['mime_type'] ?? ''), 'image/')) {
            if ($url = $this->telegram->downloadFile($channel, (string) $doc['file_id'])) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
