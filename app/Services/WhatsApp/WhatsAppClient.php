<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use App\Services\Ai\Vision\ImageLoader;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de envío hacia WhatsApp Cloud API (Meta). Portado (recortado) de
 * wa-inbox: solo el envío de texto necesario para responder en la captación.
 * Usa el token y el phone_number_id de la línea.
 */
class WhatsAppClient
{
    private function endpoint(Channel $channel): string
    {
        $version = config('whatsapp.api_version', 'v21.0');
        $base = rtrim((string) config('whatsapp.base_url', 'https://graph.facebook.com'), '/');

        return "{$base}/{$version}/{$channel->phone_number_id}/messages";
    }

    /** Envía un mensaje de texto. Devuelve el wa_message_id (wamid…) de Meta. */
    public function sendText(Channel $channel, string $to, string $body): ?string
    {
        $token = $channel->token();
        if (! $token || ! $channel->phone_number_id) {
            throw new RuntimeException('La línea de WhatsApp no tiene token o phone_number_id.');
        }

        $response = Http::withToken($token)->acceptJson()->post($this->endpoint($channel), [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? 'No se pudo enviar el mensaje de WhatsApp.');
        }

        return $response->json('messages.0.id');
    }

    /**
     * Descarga media entrante por su media_id (Graph: GET /{id} → url; luego se baja el
     * binario con el mismo bearer) y la guarda en el disco público de captación; devuelve
     * su URL. Null si falla (el mensaje se procesa sin la foto).
     */
    public function downloadMedia(Channel $channel, string $mediaId): ?string
    {
        $token = $channel->token();
        if (! $token || $mediaId === '') {
            return null;
        }

        $version = config('whatsapp.api_version', 'v21.0');
        $base    = rtrim((string) config('whatsapp.base_url', 'https://graph.facebook.com'), '/');

        try {
            $meta = Http::withToken($token)->acceptJson()->timeout(30)->get("{$base}/{$version}/{$mediaId}");
            if ($meta->failed()) {
                return null;
            }
            $url  = (string) $meta->json('url');
            $mime = (string) $meta->json('mime_type');
            if ($url === '') {
                return null;
            }

            // La descarga del binario también requiere el bearer de la app.
            $bin = Http::withToken($token)->timeout(60)->get($url);
            if ($bin->failed()) {
                return null;
            }

            $ext = match (true) {
                str_contains($mime, 'png')  => 'png',
                str_contains($mime, 'webp') => 'webp',
                str_contains($mime, 'gif')  => 'gif',
                default                     => 'jpg',
            };

            return ImageLoader::store($bin->body(), $ext);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normaliza el número destino. México móvil: WhatsApp entrega el wa_id como
     * 52 + 1 + 10 dígitos (5215512345678), pero el envío va SIN el "1"
     * (5215512345678 → 525512345678). Otros países pasan sin cambios.
     */
    public function normalizePhone(string $to): string
    {
        $digits = preg_replace('/\D+/', '', $to);

        if (str_starts_with($digits, '521') && strlen($digits) === 13) {
            return '52' . substr($digits, 3);
        }

        return $digits;
    }
}
