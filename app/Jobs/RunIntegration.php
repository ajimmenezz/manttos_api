<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Ejecuta una interacción con un sistema externo de forma AISLADA: resuelve el proveedor y
 * su configuración desde la bitácora, y llama a handleEvent (salida) o handleInbound
 * (entrada). Con reintentos y backoff. Si la integración quedó apagada o sin configurar,
 * marca la entrada como "skipped" sin error.
 */
class RunIntegration implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function __construct(public int $logId)
    {
    }

    public function handle(IntegrationManager $manager): void
    {
        $log = IntegrationLog::find($this->logId);
        if (! $log) {
            return;
        }

        $provider = $manager->provider($log->provider);
        if (! $provider) {
            $log->update(['status' => 'skipped', 'error' => 'Proveedor no disponible.']);
            return;
        }

        $config = $log->integration_id
            ? Integration::find($log->integration_id)
            : $manager->resolveConfig($log->provider, $log->client_id);

        if (! $config || ! $config->is_active || ! $provider->isConfigured($config)) {
            $log->update([
                'status'   => 'skipped',
                'error'    => 'Integración inactiva o sin configurar.',
                'attempts' => $this->attempts(),
            ]);
            return;
        }

        try {
            $response = $log->direction === 'inbound'
                ? $provider->handleInbound($log->payload ?? [], $config)
                : $provider->handleEvent($log->event_type, $log->payload ?? [], $config);

            $log->update([
                'status'       => 'success',
                'response'     => $response,
                'attempts'     => $this->attempts(),
                'delivered_at' => now(),
                'error'        => null,
            ]);
            $config->update(['last_ok_at' => now(), 'last_error' => null]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'attempts' => $this->attempts(), 'error' => Str::limit($e->getMessage(), 900, '')]);
            $config->update(['last_error_at' => now(), 'last_error' => Str::limit($e->getMessage(), 900, '')]);

            if ($this->attempts() >= $this->tries) {
                return; // último intento: queda fallida, no relanzar
            }
            throw $e;   // reintenta con backoff
        }
    }

    public function failed(\Throwable $e): void
    {
        IntegrationLog::where('id', $this->logId)
            ->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 900, '')]);
    }
}
