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
            // ── Negocio (atajos guiados) ──────────────────────────────
            ['key' => 'ping',            'label' => 'Probar conexión',          'description' => 'Verifica alcance y credenciales.', 'params' => []],
            ['key' => 'inventory',       'label' => 'Consultar inventario',     'description' => '¿Hay existencias de un producto?', 'params' => [['key' => 'sku', 'label' => 'SKU o nombre del producto', 'required' => true]]],
            ['key' => 'list_partners',   'label' => 'Buscar clientes/proveedores', 'params' => [['key' => 'query', 'label' => 'Nombre o correo (vacío = primeros)', 'required' => false]]],
            ['key' => 'list_products',   'label' => 'Buscar productos',         'params' => [['key' => 'query', 'label' => 'Nombre o SKU', 'required' => false]]],
            ['key' => 'create_quotation', 'label' => 'Crear cotización',        'description' => 'Crea un sale.order borrador para un cliente.', 'params' => [['key' => 'partner_id', 'label' => 'ID del cliente (de “Buscar clientes”)', 'required' => true]]],
            ['key' => 'get_sale_order',  'label' => 'Ver cotización / pedido',  'params' => [['key' => 'id', 'label' => 'ID del sale.order', 'required' => true]]],
            ['key' => 'create_purchase', 'label' => 'Crear orden de compra',    'params' => [['key' => 'partner_id', 'label' => 'ID del proveedor', 'required' => true]]],
            ['key' => 'get_purchase',    'label' => 'Ver orden de compra',      'params' => [['key' => 'id', 'label' => 'ID del purchase.order', 'required' => true]]],
            ['key' => 'list_invoices',   'label' => 'Facturas de venta',        'params' => []],
            ['key' => 'list_deliveries', 'label' => 'Entregas (stock.picking)', 'params' => []],

            // ── Descubrimiento ────────────────────────────────────────
            ['key' => 'list_models',     'label' => 'Descubrir · listar modelos', 'description' => 'Explora qué modelos existen en tu Odoo.', 'params' => [['key' => 'query', 'label' => 'Filtro (p. ej. sale, stock, account)', 'required' => false]]],
            ['key' => 'fields_get',      'label' => 'Descubrir · campos de un modelo', 'params' => [['key' => 'model', 'label' => 'Modelo (p. ej. sale.order)', 'required' => true]]],

            // ── Genérico: TODA la API de Odoo ─────────────────────────
            ['key' => 'search_read',     'label' => 'Avanzado · buscar en cualquier modelo', 'description' => 'search_read con dominio y campos.', 'params' => [
                ['key' => 'model', 'label' => 'Modelo', 'required' => true],
                ['key' => 'domain', 'label' => 'Dominio (JSON, p. ej. [["state","=","sale"]])', 'type' => 'textarea'],
                ['key' => 'fields', 'label' => 'Campos (separados por coma; vacío = básicos)'],
                ['key' => 'limit', 'label' => 'Límite (por defecto 20)'],
            ]],
            ['key' => 'create_record',   'label' => 'Avanzado · crear registro', 'params' => [
                ['key' => 'model', 'label' => 'Modelo', 'required' => true],
                ['key' => 'values', 'label' => 'Valores (JSON, p. ej. {"name":"X"})', 'type' => 'textarea', 'required' => true],
            ]],
            ['key' => 'update_record',   'label' => 'Avanzado · actualizar registros', 'params' => [
                ['key' => 'model', 'label' => 'Modelo', 'required' => true],
                ['key' => 'ids', 'label' => 'IDs (separados por coma)', 'required' => true],
                ['key' => 'values', 'label' => 'Valores (JSON)', 'type' => 'textarea', 'required' => true],
            ]],
            ['key' => 'delete_record',   'label' => 'Avanzado · eliminar registros', 'params' => [
                ['key' => 'model', 'label' => 'Modelo', 'required' => true],
                ['key' => 'ids', 'label' => 'IDs (separados por coma)', 'required' => true],
            ]],
            ['key' => 'execute',         'label' => 'Avanzado · ejecutar método (execute_kw)', 'description' => 'Llama cualquier método de cualquier modelo.', 'params' => [
                ['key' => 'model', 'label' => 'Modelo', 'required' => true],
                ['key' => 'method', 'label' => 'Método (p. ej. action_confirm)', 'required' => true],
                ['key' => 'args', 'label' => 'Args (JSON array, p. ej. [[42]])', 'type' => 'textarea'],
                ['key' => 'kwargs', 'label' => 'Kwargs (JSON object)', 'type' => 'textarea'],
            ]],
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
                case 'list_invoices':
                    return ['ok' => true, 'message' => 'Facturas de venta.', 'data' => $client->searchRead('account.move', [['move_type', '=', 'out_invoice']], ['name', 'partner_id', 'amount_total', 'state', 'invoice_date'], 20)];
                case 'list_deliveries':
                    return ['ok' => true, 'message' => 'Entregas.', 'data' => $client->searchRead('stock.picking', [], ['name', 'partner_id', 'state', 'scheduled_date'], 20)];

                case 'list_models':
                    return ['ok' => true, 'message' => 'Modelos.', 'data' => $client->models((string) ($params['query'] ?? ''))];
                case 'fields_get':
                    return ['ok' => true, 'message' => 'Campos del modelo.', 'data' => $client->fields((string) ($params['model'] ?? ''))];

                case 'search_read':
                    $fields = array_values(array_filter(array_map('trim', explode(',', (string) ($params['fields'] ?? '')))));
                    if (empty($fields)) $fields = ['id', 'display_name'];
                    $limit = (int) ($params['limit'] ?? 0);
                    $rows = $client->searchRead((string) ($params['model'] ?? ''), (array) $this->parseJson((string) ($params['domain'] ?? ''), []), $fields, $limit > 0 ? $limit : 20);
                    return ['ok' => true, 'message' => count($rows) . ' resultado(s).', 'data' => $rows];
                case 'create_record':
                    $model = (string) ($params['model'] ?? '');
                    $id = $client->create($model, (array) $this->parseJson((string) ($params['values'] ?? ''), []));
                    return ['ok' => true, 'message' => "Registro creado (id {$id}).", 'data' => ['id' => $id], 'url' => $client->recordUrl($model, $id)];
                case 'update_record':
                    $ok = $client->write((string) ($params['model'] ?? ''), $this->parseIds($params['ids'] ?? ''), (array) $this->parseJson((string) ($params['values'] ?? ''), []));
                    return ['ok' => $ok, 'message' => $ok ? 'Registros actualizados.' : 'No se actualizó nada.'];
                case 'delete_record':
                    $ok = $client->unlink((string) ($params['model'] ?? ''), $this->parseIds($params['ids'] ?? ''));
                    return ['ok' => $ok, 'message' => $ok ? 'Registros eliminados.' : 'No se eliminó nada.'];
                case 'execute':
                    $res = $client->execute(
                        (string) ($params['model'] ?? ''),
                        (string) ($params['method'] ?? ''),
                        (array) $this->parseJson((string) ($params['args'] ?? ''), []),
                        (array) $this->parseJson((string) ($params['kwargs'] ?? ''), []),
                    );
                    return ['ok' => true, 'message' => 'Método ejecutado.', 'data' => $res];

                default:
                    return ['ok' => false, 'message' => 'Acción desconocida: ' . $action];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Parsea un parámetro JSON; vacío = default; JSON inválido lanza para avisar al usuario. */
    private function parseJson(string $raw, $default)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON inválido: ' . json_last_error_msg());
        }
        return $decoded;
    }

    /** "1, 2,3" → [1,2,3] */
    private function parseIds($raw): array
    {
        return array_values(array_filter(
            array_map(fn ($s) => (int) trim($s), explode(',', (string) $raw)),
            fn ($n) => $n > 0,
        ));
    }
}
