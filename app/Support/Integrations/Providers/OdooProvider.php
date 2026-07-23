<?php

namespace App\Support\Integrations\Providers;

use App\Models\Integration;
use App\Services\Integrations\Odoo\OdooClient;
use App\Support\Integrations\AbstractIntegrationProvider;
use Illuminate\Support\Facades\Log;

/**
 * Conector REAL a Odoo (ERP) por JSON-RPC. Propósitos: consultar ALMACÉN/INVENTARIO (¿hay
 * refacción?), y levantar COTIZACIONES (sale.order) / órdenes de compra (purchase.order)
 * ligadas a un evento cuando aplique.
 *
 * - `query('inventory.availability')` ya lee inventario real y degrada con gracia.
 * - El "probador" ejecuta acciones reales (inventario, buscar clientes/productos, crear
 *   cotización/OC, consultar) y muestra el resultado con enlace al registro en Odoo.
 * - La creación automática desde eventos queda tras el flag `auto_quote_on_events` (apagado
 *   por defecto): qué evento "amerita" una cotización es una regla de negocio que se afina
 *   luego; mientras, el probador permite ensayar todo a mano.
 */
class OdooProvider extends AbstractIntegrationProvider
{
    public function key(): string { return 'odoo'; }
    public function label(): string { return 'Odoo'; }
    public function description(): string { return 'Cotizaciones y órdenes de compra ligadas a eventos, y consulta de almacén/inventario.'; }

    public function configSchema(): array
    {
        return [
            ['key' => 'url',       'label' => 'URL de Odoo',   'type' => 'url',     'required' => true,  'secret' => false, 'placeholder' => 'https://tu-empresa.odoo.com', 'help' => 'La dirección de tu Odoo (sin / al final).'],
            ['key' => 'db',        'label' => 'Base de datos', 'type' => 'text',    'required' => true,  'secret' => false, 'placeholder' => 'tu-empresa', 'help' => 'Nombre de la base de datos. En Odoo online suele ser el subdominio.'],
            ['key' => 'username',  'label' => 'Usuario',       'type' => 'text',    'required' => true,  'secret' => false, 'placeholder' => 'api@tu-empresa.com', 'help' => 'El correo/login del usuario de integración.'],
            ['key' => 'api_key',   'label' => 'API key',       'type' => 'password','required' => true,  'secret' => true,  'help' => 'Clave de API del usuario (Preferencias → Seguridad de la cuenta → Nueva clave API).'],
            ['key' => 'warehouse', 'label' => 'Almacén',       'type' => 'text',    'required' => false, 'secret' => false, 'placeholder' => 'WH', 'help' => 'Etiqueta del almacén por defecto para las consultas (informativo).'],
            ['key' => 'auto_quote_on_events', 'label' => 'Crear cotización automáticamente al ocurrir eventos', 'type' => 'boolean', 'required' => false, 'secret' => false, 'help' => 'Apagado por defecto. Úsalo con el probador para ensayar antes de automatizar.'],
        ];
    }

    public function subscribedEvents(): array
    {
        return [
            'event.created', 'event.updated', 'event.status_changed',
            'maintenance.created', 'maintenance.updated', 'activity.documented',
        ];
    }

    // ── Salida (automática, conservadora) ─────────────────────────────

    public function handleEvent(string $eventType, array $data, Integration $config): array
    {
        if (! filter_var($config->conf('auto_quote_on_events'), FILTER_VALIDATE_BOOL)) {
            return ['status' => 'skipped', 'note' => 'auto_quote_on_events apagado; usa el probador para ensayar.'];
        }

        // La regla de "qué evento amerita cotización" se definirá contigo; por ahora se deja
        // registrado para no crear documentos de más automáticamente.
        Log::info('[odoo] evento apto para cotización (regla pendiente)', ['type' => $eventType, 'event' => $data['id'] ?? null]);
        return ['status' => 'noted', 'note' => 'Registrado; la regla de negocio para auto-cotización se definirá al integrar.'];
    }

    // ── Consulta síncrona: inventario real ────────────────────────────

    public function query(string $operation, array $params, Integration $config): array
    {
        if ($operation !== 'inventory.availability') {
            return parent::query($operation, $params, $config);
        }
        try {
            return (new OdooClient($config))->inventory((string) ($params['sku'] ?? $params['name'] ?? ''));
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function handleInbound(array $payload, Integration $config): array
    {
        // TODO(integración real): p. ej. cambios de estado de una OC/cotización de vuelta.
        return ['status' => 'received', 'note' => 'Entrada registrada (mapeo de vuelta pendiente).'];
    }

    // ── Prueba de conexión (alcance + credenciales) ───────────────────

    public function testConnection(Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Faltan datos de configuración.'];
        }
        try {
            $client = new OdooClient($config);
            $ver = $client->version();
            $uid = $client->uid(); // valida credenciales
            return ['ok' => true, 'message' => 'Conectado a Odoo ' . ($ver['server_version'] ?? '?') . " (uid {$uid})."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Probador de acciones ──────────────────────────────────────────

    public function supportedActions(): array
    {
        return [
            ['key' => 'ping',            'label' => 'Probar conexión',          'description' => 'Verifica alcance y credenciales.', 'params' => []],
            ['key' => 'inventory',       'label' => 'Consultar inventario',     'description' => '¿Hay existencias de un producto?', 'params' => [['key' => 'sku', 'label' => 'SKU o nombre del producto', 'required' => true]]],
            ['key' => 'list_partners',   'label' => 'Buscar clientes/proveedores', 'params' => [['key' => 'query', 'label' => 'Nombre o correo (vacío = primeros)', 'required' => false]]],
            ['key' => 'list_products',   'label' => 'Buscar productos',         'params' => [['key' => 'query', 'label' => 'Nombre o SKU', 'required' => false]]],
            ['key' => 'create_quotation', 'label' => 'Crear cotización de prueba', 'description' => 'Crea un sale.order borrador para un cliente.', 'params' => [['key' => 'partner_id', 'label' => 'ID del cliente (de “Buscar clientes”)', 'required' => true]]],
            ['key' => 'get_sale_order',  'label' => 'Ver cotización / pedido',  'params' => [['key' => 'id', 'label' => 'ID del sale.order', 'required' => true]]],
            ['key' => 'create_purchase', 'label' => 'Crear orden de compra de prueba', 'params' => [['key' => 'partner_id', 'label' => 'ID del proveedor', 'required' => true]]],
            ['key' => 'get_purchase',    'label' => 'Ver orden de compra',      'params' => [['key' => 'id', 'label' => 'ID del purchase.order', 'required' => true]]],
        ];
    }

    public function runAction(string $action, array $params, Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Configura y guarda la conexión antes de probar acciones.'];
        }

        $client = new OdooClient($config);
        try {
            switch ($action) {
                case 'ping':
                    $ver = $client->version();
                    $uid = $client->uid();
                    return ['ok' => true, 'message' => 'Conectado a Odoo ' . ($ver['server_version'] ?? '?') . " (uid {$uid}).", 'data' => $ver];
                case 'inventory':
                    $r = $client->inventory((string) ($params['sku'] ?? ''));
                    return ['ok' => true, 'message' => $r['available'] ? "Disponibles: {$r['quantity']}" : 'Sin existencias / no encontrado.', 'data' => $r];
                case 'list_partners':
                    return ['ok' => true, 'message' => 'Clientes/proveedores.', 'data' => $client->partners((string) ($params['query'] ?? ''))];
                case 'list_products':
                    return ['ok' => true, 'message' => 'Productos.', 'data' => $client->products((string) ($params['query'] ?? ''))];
                case 'create_quotation':
                    $r = $client->createQuotation((int) ($params['partner_id'] ?? 0));
                    return ['ok' => true, 'message' => 'Cotización creada: ' . ($r['name'] ?? $r['id']), 'data' => $r, 'url' => $r['url']];
                case 'get_sale_order':
                    $r = $client->getSaleOrder((int) ($params['id'] ?? 0));
                    return ['ok' => true, 'message' => 'Pedido ' . ($r['name'] ?? ''), 'data' => $r, 'url' => $r['url'] ?? null];
                case 'create_purchase':
                    $r = $client->createPurchaseOrder((int) ($params['partner_id'] ?? 0));
                    return ['ok' => true, 'message' => 'Orden de compra creada: ' . ($r['name'] ?? $r['id']), 'data' => $r, 'url' => $r['url']];
                case 'get_purchase':
                    $r = $client->getPurchaseOrder((int) ($params['id'] ?? 0));
                    return ['ok' => true, 'message' => 'OC ' . ($r['name'] ?? ''), 'data' => $r, 'url' => $r['url'] ?? null];
                default:
                    return ['ok' => false, 'message' => 'Acción desconocida: ' . $action];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
