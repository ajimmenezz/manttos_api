<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
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
