<?php

namespace App\Services\Webhooks;

use App\Jobs\SendWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Punto único para emitir un evento de negocio por webhook. Resuelve los endpoints con
 * alcance sobre ese cliente/sitio y suscritos al tipo, crea una entrada de bitácora por
 * cada uno y encola su entrega.
 *
 * Espejo de App\Services\Notifications\Notifier, pero hacia afuera (terceros), no a los
 * usuarios internos. Se llama después de confirmar la transacción del negocio.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string,mixed>  $data  payload ya serializado (ver WebhookEvent::eventData)
     */
    public function dispatch(string $eventType, int $clientId, ?int $siteId, array $data): void
    {
        $endpoints = WebhookEndpoint::where('client_id', $clientId)
            ->where('is_active', true)
            ->where(function ($q) use ($siteId) {
                // site_id nulo = todos los sitios del cliente; si el evento trae sitio,
                // también entran los endpoints atados a ESE sitio.
                $q->whereNull('site_id');
                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->subscribedTo($eventType)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'event_type'          => $eventType,
                'payload'             => $data,
                'status'              => 'pending',
            ]);

            SendWebhook::dispatch($delivery->id);
        }
    }
}
