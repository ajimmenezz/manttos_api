<?php

namespace App\Services\Integrations;

use App\Jobs\RunIntegration;
use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Support\Integrations\IntegrationProvider;
use App\Support\Integrations\Providers\JiraServiceManagementProvider;
use App\Support\Integrations\Providers\OdooProvider;

/**
 * Punto único de las integraciones externas. Registra los proveedores, resuelve la
 * configuración por alcance (override de cliente > global), empuja eventos de salida a
 * través de un job aislado y ofrece consultas síncronas que degradan con gracia.
 *
 * GARANTÍA: ni `dispatch` ni `query` lanzan hacia la petición del negocio. Si los sistemas
 * externos no existen o no están configurados, esto simplemente no hace nada.
 */
class IntegrationManager
{
    /**
     * Proveedores registrados. Agregar uno nuevo = una clase más aquí.
     *
     * @return array<string,IntegrationProvider>
     */
    public function providers(): array
    {
        return [
            'odoo' => new OdooProvider(),
            'jira' => new JiraServiceManagementProvider(),
        ];
    }

    public function provider(string $key): ?IntegrationProvider
    {
        return $this->providers()[$key] ?? null;
    }

    /**
     * Config activa y configurada para (proveedor, cliente): primero el override del
     * cliente; si no hay, la global. Devuelve null si no aplica ninguna.
     */
    public function resolveConfig(string $provider, ?int $clientId): ?Integration
    {
        $p = $this->provider($provider);
        if (! $p) {
            return null;
        }

        if ($clientId) {
            $override = Integration::where('provider', $provider)
                ->where('client_id', $clientId)->where('is_active', true)->first();
            if ($override && $p->isConfigured($override)) {
                return $override;
            }
        }

        $global = Integration::where('provider', $provider)
            ->whereNull('client_id')->where('is_active', true)->first();
        if ($global && $p->isConfigured($global)) {
            return $global;
        }

        return null;
    }

    /**
     * Empuja un evento de negocio (SALIDA) a cada proveedor con configuración resuelta.
     * Crea una entrada de bitácora y encola su entrega. Nunca rompe el negocio.
     *
     * @param  array<string,mixed>  $data
     */
    public function dispatch(string $eventType, ?int $clientId, array $data): void
    {
        try {
            foreach ($this->providers() as $key => $provider) {
                $subscribed = $provider->subscribedEvents();
                if (! empty($subscribed) && ! in_array($eventType, $subscribed, true)) {
                    continue;
                }

                $config = $this->resolveConfig($key, $clientId);
                if (! $config) {
                    continue; // apagado / sin configurar → no-op silencioso
                }

                $log = IntegrationLog::create([
                    'integration_id' => $config->id,
                    'provider'       => $key,
                    'client_id'      => $clientId,
                    'direction'      => 'outbound',
                    'event_type'     => $eventType,
                    'status'         => 'pending',
                    'payload'        => $data,
                ]);

                RunIntegration::dispatch($log->id);
            }
        } catch (\Throwable $e) {
            // Una falla de las integraciones jamás debe tumbar la acción del negocio.
            report($e);
        }
    }

    /**
     * Consulta síncrona a un proveedor (p. ej. inventario en Odoo). SIEMPRE devuelve un
     * arreglo; ante cualquier problema degrada a un resultado "no disponible".
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function query(string $provider, ?int $clientId, string $operation, array $params = []): array
    {
        try {
            $p = $this->provider($provider);
            if (! $p) {
                return ['available' => false, 'reason' => 'unknown_provider'];
            }

            $config = $this->resolveConfig($provider, $clientId);
            if (! $config) {
                return ['available' => false, 'reason' => 'not_configured'];
            }

            return $p->query($operation, $params, $config);
        } catch (\Throwable $e) {
            report($e);
            return ['available' => false, 'reason' => 'error'];
        }
    }
}
