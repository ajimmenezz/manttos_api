<?php

namespace App\Services\Telegram;

use App\Models\Channel;
use App\Services\Ai\Vision\ImageLoader;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de la Telegram Bot API. El token del bot vive cifrado en
 * `channels.access_token`. Portado (recortado) de wa-inbox: solo lo necesario
 * para captación de eventos (validar bot, enviar texto, long-polling y webhook).
 */
class TelegramClient
{
    private const API = 'https://api.telegram.org';

    /** Valida un token crudo y devuelve los datos del bot (getMe). Lanza si es inválido. */
    public function getMe(string $token): array
    {
        $res = Http::asJson()->timeout(15)->get(self::API . "/bot{$token}/getMe");
        if (! $res->successful() || ! $res->json('ok')) {
            throw new RuntimeException('Token de Telegram inválido: ' . ($res->json('description') ?? ('HTTP ' . $res->status())));
        }

        return $res->json('result');
    }

    /** Envía un mensaje de texto. Devuelve el message_id compuesto tg:{chatId}:{id}. */
    public function sendText(Channel $channel, string $chatId, string $body): ?string
    {
        $result = $this->call($channel, 'sendMessage', ['chat_id' => $chatId, 'text' => $body]);
        $id = $result['message_id'] ?? null;

        return $id ? "tg:{$chatId}:{$id}" : null;
    }

    /** Long-polling: trae updates desde $offset. */
    public function getUpdates(Channel $channel, int $offset, int $timeout = 30): array
    {
        $res = Http::timeout($timeout + 10)->get($this->url($channel, 'getUpdates'), [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'allowed_updates' => json_encode(['message', 'edited_message']),
        ]);

        return $res->successful() && $res->json('ok') ? ($res->json('result') ?? []) : [];
    }

    public function setWebhook(Channel $channel, string $url, string $secretToken): array
    {
        return $this->call($channel, 'setWebhook', [
            'url'             => $url,
            'secret_token'    => $secretToken,
            'allowed_updates' => ['message', 'edited_message'],
        ]);
    }

    public function deleteWebhook(Channel $channel): array
    {
        return $this->call($channel, 'deleteWebhook', []);
    }

    /**
     * Descarga un archivo por file_id (getFile → file/bot{token}/{path}) y lo guarda en
     * el disco público de media de captación; devuelve su URL. Null si falla (no rompe
     * la captación: el mensaje se procesa sin la foto).
     */
    public function downloadFile(Channel $channel, string $fileId): ?string
    {
        $token = $channel->token();
        if (! $token || $fileId === '') {
            return null;
        }

        try {
            $meta = $this->call($channel, 'getFile', ['file_id' => $fileId]);
            $path = $meta['file_path'] ?? null;
            if (! $path) {
                return null;
            }

            $res = Http::timeout(60)->get(self::API . "/file/bot{$token}/{$path}");
            if ($res->failed()) {
                return null;
            }

            return ImageLoader::store($res->body(), pathinfo((string) $path, PATHINFO_EXTENSION) ?: 'jpg');
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Internos ────────────────────────────────────────────

    private function url(Channel $channel, string $method): string
    {
        $token = $channel->token();
        if (! $token) {
            throw new RuntimeException('La línea de Telegram no tiene bot token configurado.');
        }

        return self::API . "/bot{$token}/{$method}";
    }

    private function call(Channel $channel, string $method, array $payload): array
    {
        $res = Http::timeout(30)->asJson()->post($this->url($channel, $method), $payload);
        if (! $res->successful() || ! $res->json('ok')) {
            throw new RuntimeException("Telegram {$method}: " . ($res->json('description') ?? ('HTTP ' . $res->status())));
        }

        return $res->json('result') ?? [];
    }
}
