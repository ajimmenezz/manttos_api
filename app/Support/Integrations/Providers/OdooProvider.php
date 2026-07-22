<?php

namespace App\Support\Integrations\Providers;

use App\Models\Integration;
use App\Support\Integrations\AbstractIntegrationProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conector a Odoo (ERP). Propósitos previstos: a partir de un evento que lo requiera,
 * levantar una COTIZACIÓN y dar seguimiento a la OC; consultar ALMACÉN/INVENTARIO para
 * saber si hay refacciones/dispositivos; y sincronizar catálogos.
 *
 * ESTADO: cableado. `testConnection` verifica alcance real vía JSON-RPC (servicio
 * `common.version`, sin credenciales de escritura). `handleEvent` y `query` están en
 * modo stub seguro: preparan/registran la intención pero aún no escriben ni leen datos
 * reales. Los puntos donde irá la llamada real están marcados con TODO.
 */
class OdooProvider extends AbstractIntegrationProvider
{
    public function key(): string { return 'odoo'; }
    public function label(): string { return 'Odoo'; }
    public function description(): string { return 'Cotizaciones y órdenes de compra ligadas a eventos, y consulta de almacén/inventario.'; }

    public function configSchema(): array
    {
        return [
            ['key' => 'url',       'label' => 'URL de Odoo',   'type' => 'url',     'required' => true,  'secret' => false, 'placeholder' => 'https://tu-empresa.odoo.com'],
            ['key' => 'db',        'label' => 'Base de datos', 'type' => 'text',    'required' => true,  'secret' => false, 'placeholder' => 'tu-empresa', 'help' => 'Nombre de la base de datos de Odoo.'],
            ['key' => 'username',  'label' => 'Usuario',       'type' => 'text',    'required' => true,  'secret' => false, 'placeholder' => 'api@tu-empresa.com'],
            ['key' => 'api_key',   'label' => 'API key',       'type' => 'password','required' => true,  'secret' => true,  'help' => 'Clave de API del usuario (Preferencias → Seguridad de la cuenta).'],
            ['key' => 'warehouse', 'label' => 'Almacén',       'type' => 'text',    'required' => false, 'secret' => false, 'placeholder' => 'WH', 'help' => 'Almacén por defecto para consultas de inventario.'],
        ];
    }

    /** Odoo se interesa por eventos y por el ciclo de mantenimientos/actividades. */
    public function subscribedEvents(): array
    {
        return [
            'event.created', 'event.updated', 'event.status_changed',
            'maintenance.created', 'maintenance.updated', 'activity.documented',
        ];
    }

    public function handleEvent(string $eventType, array $data, Integration $config): array
    {
        // TODO(integración real): según el evento, crear cotización (sale.order),
        // orden de compra (purchase.order), o registrar la actividad. Por ahora solo prepara.
        Log::info('[odoo] evento preparado (sin enviar todavía)', ['type' => $eventType, 'db' => $config->conf('db')]);

        return ['status' => 'prepared', 'event_type' => $eventType, 'note' => 'Cableado listo; falta activar la escritura real en Odoo.'];
    }

    /**
     * Consulta síncrona. Soporta 'inventory.availability' (¿hay refacción/dispositivo?).
     * Degrada SIEMPRE con gracia: si Odoo no está o falla, `available=false` sin lanzar.
     */
    public function query(string $operation, array $params, Integration $config): array
    {
        if ($operation !== 'inventory.availability') {
            return parent::query($operation, $params, $config);
        }

        // TODO(integración real): leer stock.quant / product.product por SKU y devolver
        // la cantidad disponible en el almacén configurado.
        //   $qty = $this->rpc($config, 'stock.quant', 'search_read', [...]);
        return [
            'available' => false,
            'reason'    => 'pending_implementation',
            'sku'       => $params['sku'] ?? null,
            'warehouse' => $config->conf('warehouse'),
            'note'      => 'Consulta cableada; la lectura real de inventario se implementará al integrar.',
        ];
    }

    public function handleInbound(array $payload, Integration $config): array
    {
        // TODO(integración real): p. ej. cambios de estado de una OC/OT de vuelta.
        Log::info('[odoo] entrada recibida', ['keys' => array_keys($payload)]);
        return ['status' => 'received', 'note' => 'Registrado; el mapeo de vuelta se implementará al integrar.'];
    }

    public function testConnection(Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Faltan datos de configuración.'];
        }

        try {
            // Servicio 'common', método 'version': no requiere autenticación; confirma alcance.
            $res = Http::acceptJson()->timeout(10)->post(rtrim((string) $config->conf('url'), '/') . '/jsonrpc', [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'params'  => ['service' => 'common', 'method' => 'version', 'args' => []],
                'id'      => 1,
            ]);

            if ($res->successful() && $res->json('result')) {
                $ver = $res->json('result.server_version') ?? '?';
                return ['ok' => true, 'message' => "Odoo alcanzable (versión {$ver}). Falta validar credenciales al escribir."];
            }
            return ['ok' => false, 'message' => 'Odoo respondió ' . $res->status() . '. Revisa la URL.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar: ' . $e->getMessage()];
        }
    }
}
