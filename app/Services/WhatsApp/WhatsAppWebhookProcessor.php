<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use App\Services\Capture\InboundHandler;

/**
 * Normaliza el payload del webhook de WhatsApp (Meta) y entrega cada mensaje de
 * texto al pipeline de captación. La línea se resuelve por phone_number_id.
 */
class WhatsAppWebhookProcessor
{
    public function __construct(private InboundHandler $handler) {}

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
                    $text = trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? ''));
                    if ($text === '') {
                        $text = '(envié un archivo o mensaje sin texto)';
                    }
                    $this->handler->handle($channel, (string) $from, $names[$from] ?? null, $text, $msg['id'] ?? null);
                }
            }
        }
    }
}
