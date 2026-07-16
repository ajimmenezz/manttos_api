<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use App\Services\Capture\InboundHandler;

/**
 * Normaliza el payload del webhook de WhatsApp (Meta) y entrega cada mensaje al
 * pipeline de captación. Maneja texto e IMÁGENES (image/document imagen): descarga
 * la media y la adjunta para que el agente la vea y se ate al evento. La línea se
 * resuelve por phone_number_id.
 */
class WhatsAppWebhookProcessor
{
    public function __construct(
        private InboundHandler $handler,
        private WhatsAppClient $whatsapp,
    ) {}

    public function process(array $payload): void
    {
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $pnid  = $value['metadata']['phone_number_id'] ?? null;
                if (! $pnid) {
                    continue;
                }

                $channel = Channel::where('provider', Channel::PROVIDER_WHATSAPP)
                    ->where('phone_number_id', $pnid)->where('is_active', true)->first();
                if (! $channel) {
                    continue;
                }

                // Nombre del contacto por wa_id.
                $names = [];
                foreach (($value['contacts'] ?? []) as $c) {
                    if (! empty($c['wa_id'])) {
                        $names[$c['wa_id']] = $c['profile']['name'] ?? null;
                    }
                }

                foreach (($value['messages'] ?? []) as $msg) {
                    $from = $msg['from'] ?? null;
                    if (! $from) {
                        continue;
                    }

                    $imageUrls = $this->extractImages($channel, $msg);

                    $text = trim((string) ($msg['text']['body'] ?? $msg['image']['caption'] ?? $msg['document']['caption'] ?? $msg['button']['text'] ?? ''));
                    if ($text === '') {
                        $text = $imageUrls ? '(envié una foto)' : '(envié un archivo o mensaje sin texto)';
                    }
                    $this->handler->handle($channel, (string) $from, $names[$from] ?? null, $text, $msg['id'] ?? null, null, null, $imageUrls);
                }
            }
        }
    }

    /**
     * Extrae y descarga la imagen del mensaje: `image` o un `document` con mime image/*.
     *
     * @return array<int,string>
     */
    private function extractImages(Channel $channel, array $msg): array
    {
        $mediaId = null;
        if (! empty($msg['image']['id'])) {
            $mediaId = (string) $msg['image']['id'];
        } elseif (! empty($msg['document']['id']) && str_starts_with((string) ($msg['document']['mime_type'] ?? ''), 'image/')) {
            $mediaId = (string) $msg['document']['id'];
        }
        if (! $mediaId) {
            return [];
        }

        $url = $this->whatsapp->downloadMedia($channel, $mediaId);

        return $url ? [$url] : [];
    }
}
