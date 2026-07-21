<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\WebhookUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Entrega de un webhook saliente: POST firmado (HMAC-SHA256) a la URL del tercero, con
 * reintentos y backoff, dejando constancia del resultado en webhook_deliveries.
 *
 * La firma cubre `timestamp.body`; el receptor la verifica recomputando
 * hash_hmac('sha256', timestamp + '.' + rawBody, secret) y comparando con hash_equals.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Backoff creciente entre reintentos (segundos): 5 intentos en total. */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->is_active) {
            $delivery->update(['status' => 'failed', 'error' => 'El webhook fue desactivado o eliminado.']);
            return;
        }

        // Anti-SSRF también al entregar: el DNS pudo cambiar tras el alta.
        if (! WebhookUrl::isSafe($endpoint->url)) {
            $delivery->update(['status' => 'failed', 'error' => 'URL bloqueada (dirección interna).']);
            return;
        }

        $body = json_encode([
            'event'       => $delivery->event_type,
            'delivery_id' => $delivery->id,
            'occurred_at' => $delivery->created_at?->toISOString(),
            'data'        => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $endpoint->secret);
        $attempt   = $this->attempts();

        try {
            $res = Http::withHeaders([
                'User-Agent'          => 'Siccob-Webhooks/1.0',
                'X-Siccob-Event'      => $delivery->event_type,
                'X-Siccob-Delivery'   => (string) $delivery->id,
                'X-Siccob-Timestamp'  => $timestamp,
                'X-Siccob-Signature'  => 'sha256=' . $signature,
            ])->timeout(10)->withBody($body, 'application/json')->post($endpoint->url);

            $delivery->update([
                'attempts'        => $attempt,
                'response_status' => $res->status(),
                'response_body'   => Str::limit($res->body(), 2000, ''),
                'error'           => null,
            ]);

            if ($res->successful()) {
                $delivery->update(['status' => 'success', 'delivered_at' => now()]);
                $endpoint->update(['last_success_at' => now()]);
                return;
            }

            throw new \RuntimeException("El destino respondió HTTP {$res->status()}.");
        } catch (\Throwable $e) {
            $delivery->update(['attempts' => $attempt, 'error' => Str::limit($e->getMessage(), 500, '')]);
            $endpoint->update(['last_failure_at' => now()]);

            // Último intento: se marca fallida y NO se relanza (evita doble marca con failed()).
            if ($attempt >= $this->tries) {
                $delivery->update(['status' => 'failed']);
                return;
            }

            throw $e;   // reintenta con backoff
        }
    }

    public function failed(\Throwable $e): void
    {
        WebhookDelivery::where('id', $this->deliveryId)
            ->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500, '')]);
    }
}
