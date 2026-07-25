<?php

namespace App\Services\Integrations\Odoo;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de Odoo por JSON-RPC (endpoint /jsonrpc). Autentica con db + usuario + API key
 * (la API key va como "password" en Odoo 14+) y ejecuta métodos de modelos vía execute_kw.
 *
 * Cada método devuelve datos en éxito o LANZA \RuntimeException con el error real de Odoo,
 * para que el probador lo muestre y el job reintente. Degrada sin tumbar el negocio.
 */
class OdooClient
{
    private ?int $uidCache = null;
    private int $reqId = 0;

    public function __construct(private Integration $config)
    {
    }

    // ── Descubrimiento / sesión ───────────────────────────────────────

    /** Versión del servidor (no requiere credenciales). */
    public function version(): array
    {
        return $this->rpc('common', 'version', []);
    }

    /** Autentica y devuelve el uid (entero). Lanza si las credenciales fallan. */
    public function uid(): int
    {
        if ($this->uidCache !== null) {
            return $this->uidCache;
        }
        $uid = $this->rpc('common', 'authenticate', [
            (string) $this->config->conf('db'),
            (string) $this->config->conf('username'),
            (string) $this->config->conf('api_key'),
            new \stdClass(),
        ]);
        if (! is_int($uid) || $uid <= 0) {
            throw new \RuntimeException('Odoo rechazó las credenciales (db / usuario / API key).');
        }
        return $this->uidCache = $uid;
    }

    // ── execute_kw genérico ───────────────────────────────────────────

    /**
     * Ejecuta un método de un modelo (execute_kw).
     *
     * @param  array<int,mixed>  $args
     * @param  array<string,mixed>  $kwargs
     * @return mixed
     */
    public function execute(string $model, string $method, array $args = [], array $kwargs = [])
    {
        return $this->rpc('object', 'execute_kw', [
            (string) $this->config->conf('db'),
            $this->uid(),
            (string) $this->config->conf('api_key'),
            $model,
            $method,
            $args,
            (object) $kwargs,   // kwargs debe ir como objeto JSON aunque esté vacío
        ]);
    }

    /**
     * @param  array<int,mixed>  $domain
     * @param  array<int,string>  $fields
     * @return array<int,array<string,mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, int $limit = 20): array
    {
        $res = $this->execute($model, 'search_read', [$domain], ['fields' => $fields, 'limit' => $limit]);
        return is_array($res) ? $res : [];
    }

    /** @param array<string,mixed> $values */
    public function create(string $model, array $values): int
    {
        $id = $this->execute($model, 'create', [(object) $values]);
        return (int) $id;
    }

    /**
     * @param  array<int,int>  $ids
     * @param  array<int,string>  $fields
     * @return array<int,array<string,mixed>>
     */
    public function read(string $model, array $ids, array $fields): array
    {
        $res = $this->execute($model, 'read', [$ids], ['fields' => $fields]);
        return is_array($res) ? $res : [];
    }

    // ── Descubrimiento de modelos/campos (API completa) ───────────────

    /** Lista modelos (ir.model) para descubrir qué existe. */
    public function models(string $query = '', int $limit = 100): array
    {
        $domain = $query === '' ? [] : ['|', ['model', 'ilike', $query], ['name', 'ilike', $query]];
        return $this->searchRead('ir.model', $domain, ['model', 'name'], $limit);
    }

    /** Campos de un modelo (nombre, etiqueta, tipo). */
    public function fields(string $model): array
    {
        $res = $this->execute($model, 'fields_get', [], ['attributes' => ['string', 'type']]);
        $out = [];
        if (is_array($res)) {
            foreach ($res as $name => $meta) {
                $out[] = ['field' => $name, 'label' => $meta['string'] ?? null, 'type' => $meta['type'] ?? null];
            }
        }
        return $out;
    }

    /** Actualiza registros (write). @param array<int,int> $ids @param array<string,mixed> $values */
    public function write(string $model, array $ids, array $values): bool
    {
        return (bool) $this->execute($model, 'write', [$ids, (object) $values]);
    }

    /** Elimina registros (unlink). @param array<int,int> $ids */
    public function unlink(string $model, array $ids): bool
    {
        return (bool) $this->execute($model, 'unlink', [$ids]);
    }

    // ── Operaciones de negocio ────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function partners(string $query, int $limit = 20): array
    {
        $domain = $query === '' ? [] : ['|', ['name', 'ilike', $query], ['email', 'ilike', $query]];
        return $this->searchRead('res.partner', $domain, ['id', 'name', 'email'], $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function products(string $query, int $limit = 20): array
    {
        $domain = $query === '' ? [] : ['|', ['name', 'ilike', $query], ['default_code', 'ilike', $query]];
        return $this->searchRead('product.product', $domain, ['id', 'name', 'default_code', 'qty_available'], $limit);
    }

    /** Disponibilidad de un producto por SKU (default_code) o nombre. */
    public function inventory(string $skuOrName): array
    {
        $rows = $this->searchRead('product.product',
            ['|', ['default_code', '=', $skuOrName], ['name', 'ilike', $skuOrName]],
            ['id', 'name', 'default_code', 'qty_available', 'virtual_available'], 10);

        $total = array_sum(array_map(fn ($r) => (float) ($r['qty_available'] ?? 0), $rows));
        return [
            'available' => $total > 0,
            'quantity'  => $total,
            'warehouse' => $this->config->conf('warehouse'),
            'matches'   => $rows,
        ];
    }

    public function createQuotation(int $partnerId, array $extra = []): array
    {
        $id = $this->create('sale.order', array_merge(['partner_id' => $partnerId], $extra));
        $row = $this->read('sale.order', [$id], ['name', 'state', 'amount_total'])[0] ?? [];
        return ['id' => $id, 'name' => $row['name'] ?? null, 'state' => $row['state'] ?? null, 'url' => $this->recordUrl('sale.order', $id)];
    }

    /**
     * Crea una cotización (sale.order) con cliente + líneas de producto. Cada línea:
     *   ['product' => id|sku, 'quantity' => n, 'price' => n?, 'description' => str?]
     * El `product` se resuelve por ID (numérico) o por SKU/nombre (resolveProductId).
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<string,mixed>  $extra  campos extra del sale.order (p. ej. client_order_ref)
     */
    public function createQuotationWithLines(int $partnerId, array $lines, array $extra = []): array
    {
        $orderLine = [];
        foreach ($lines as $line) {
            $productRef = $line['product'] ?? null;
            if ($productRef === null || $productRef === '') {
                continue; // línea sin producto → se omite
            }
            $productId = $this->resolveProductId((string) $productRef);
            $vals = ['product_id' => $productId];

            $qty = $line['quantity'] ?? null;
            if ($qty !== null && $qty !== '' && is_numeric($qty)) {
                $vals['product_uom_qty'] = (float) $qty;
            }
            if (isset($line['price']) && $line['price'] !== '' && is_numeric($line['price'])) {
                $vals['price_unit'] = (float) $line['price'];
            }
            if (! empty($line['description'])) {
                $vals['name'] = (string) $line['description'];
            }
            // Comando One2many de Odoo: (0, 0, {valores}) = crear línea nueva.
            $orderLine[] = [0, 0, $vals];
        }

        abort_if(empty($orderLine), 422, 'No se pudo armar ninguna línea de la cotización (revisa el mapeo de productos).');

        $id = $this->create('sale.order', array_merge([
            'partner_id' => $partnerId,
            'order_line' => $orderLine,
        ], $extra));
        $row = $this->read('sale.order', [$id], ['name', 'state', 'amount_total'])[0] ?? [];
        return [
            'id'    => $id,
            'name'  => $row['name'] ?? null,
            'state' => $row['state'] ?? null,
            'amount_total' => $row['amount_total'] ?? null,
            'lines' => count($orderLine),
            'url'   => $this->recordUrl('sale.order', $id),
        ];
    }

    /**
     * Resuelve un producto de Odoo a partir de un valor que puede ser el ID (numérico) o el
     * SKU (default_code) / nombre. Lanza si no se encuentra, para que la corrida quede fallida
     * con un mensaje claro en vez de crear una cotización a medias.
     */
    public function resolveProductId(string $value): int
    {
        $value = trim($value);
        if ($value !== '' && ctype_digit($value)) {
            return (int) $value; // ya es un ID de Odoo
        }
        // Por SKU exacto (default_code) primero; si no, por nombre aproximado.
        $rows = $this->searchRead('product.product', [['default_code', '=', $value]], ['id'], 1);
        if (empty($rows)) {
            $rows = $this->searchRead('product.product', [['name', 'ilike', $value]], ['id'], 1);
        }
        abort_if(empty($rows), 422, "Producto no encontrado en Odoo: «{$value}» (ni por ID, SKU ni nombre).");
        return (int) $rows[0]['id'];
    }

    public function getSaleOrder(int $id): array
    {
        $row = $this->read('sale.order', [$id], ['name', 'state', 'partner_id', 'amount_total', 'date_order'])[0] ?? [];
        return array_merge($row, ['url' => $this->recordUrl('sale.order', $id)]);
    }

    public function createPurchaseOrder(int $partnerId, array $extra = []): array
    {
        $id = $this->create('purchase.order', array_merge(['partner_id' => $partnerId], $extra));
        $row = $this->read('purchase.order', [$id], ['name', 'state', 'amount_total'])[0] ?? [];
        return ['id' => $id, 'name' => $row['name'] ?? null, 'state' => $row['state'] ?? null, 'url' => $this->recordUrl('purchase.order', $id)];
    }

    public function getPurchaseOrder(int $id): array
    {
        $row = $this->read('purchase.order', [$id], ['name', 'state', 'partner_id', 'amount_total', 'date_order'])[0] ?? [];
        return array_merge($row, ['url' => $this->recordUrl('purchase.order', $id)]);
    }

    public function recordUrl(string $model, int $id): string
    {
        return rtrim((string) $this->config->conf('url'), '/') . "/web#id={$id}&model={$model}&view_type=form";
    }

    // ── JSON-RPC interno ──────────────────────────────────────────────

    /**
     * @param  array<int,mixed>  $args
     * @return mixed
     */
    private function rpc(string $service, string $method, array $args)
    {
        $res = Http::acceptJson()->asJson()->timeout(20)
            ->post(rtrim((string) $this->config->conf('url'), '/') . '/jsonrpc', [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'params'  => ['service' => $service, 'method' => $method, 'args' => $args],
                'id'      => ++$this->reqId,
            ]);

        if (! $res->successful()) {
            throw new \RuntimeException("Odoo HTTP {$res->status()}: " . trim(mb_substr($res->body(), 0, 200)));
        }

        $json = $res->json();
        if (is_array($json) && isset($json['error'])) {
            $err = $json['error'];
            $msg = $err['data']['message'] ?? $err['message'] ?? 'Error de Odoo.';
            throw new \RuntimeException('Odoo: ' . $msg);
        }

        return $json['result'] ?? null;
    }
}
