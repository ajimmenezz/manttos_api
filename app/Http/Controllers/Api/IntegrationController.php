<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de integraciones externas (Odoo, Jira, …). Alcance global (client_id NULL)
 * o por cliente (override). Superadmin-only por defecto: maneja credenciales sensibles.
 *
 * Los secretos NUNCA se devuelven en crudo: se enmascaran y, al guardar, un campo secreto
 * vacío conserva el valor previo.
 */
class IntegrationController extends Controller
{
    public function __construct(private IntegrationManager $manager)
    {
    }

    // ── Catálogo de proveedores + configuraciones existentes ──────────
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('integrations.view'), 403, 'No autorizado para esta acción.');

        $providers = collect($this->manager->providers())->map(fn ($p) => [
            'key'              => $p->key(),
            'label'            => $p->label(),
            'description'      => $p->description(),
            'schema'           => $p->configSchema(),
            'subscribed_events' => $p->subscribedEvents(),
        ])->values();

        $integrations = Integration::with('client:id,name')->get()
            ->map(fn (Integration $i) => $this->serialize($i));

        return response()->json([
            'providers'    => $providers,
            'integrations' => $integrations,
            'clients'      => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ── Crear/actualizar por alcance (provider + client_id) ───────────
    public function upsert(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'provider'  => ['required', 'string'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'is_active' => ['boolean'],
            'config'    => ['array'],
        ]);

        $provider = $this->manager->provider($data['provider']);
        abort_unless($provider, 422, 'Proveedor de integración desconocido.');

        $clientId = $data['client_id'] ?? null;

        $integration = Integration::firstOrNew([
            'provider'  => $data['provider'],
            'client_id' => $clientId,
        ]);

        // Merge de config: un campo secreto vacío conserva el valor previo.
        $existing = $integration->config ?? [];
        $incoming = $data['config'] ?? [];
        $merged   = $existing;
        foreach ($provider->configSchema() as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $value = $incoming[$key];
            if (($field['secret'] ?? false) && ($value === null || $value === '')) {
                continue; // no pisar el secreto guardado
            }
            $merged[$key] = $value;
        }

        $integration->config    = $merged;
        $integration->is_active = $data['is_active'] ?? $integration->is_active ?? false;
        if (! $integration->inbound_secret) {
            $integration->inbound_secret = Integration::freshInboundSecret();
        }
        if (! $integration->exists) {
            $integration->created_by = $request->user()->id;
        }
        $integration->save();

        return response()->json($this->serialize($integration->fresh('client')));
    }

    // ── Probar la conexión ────────────────────────────────────────────
    public function test(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403, 'No autorizado para esta acción.');

        $provider = $this->manager->provider($integration->provider);
        abort_unless($provider, 422, 'Proveedor desconocido.');

        $result = $provider->testConnection($integration);

        $integration->update($result['ok']
            ? ['last_ok_at' => now(), 'last_error' => null]
            : ['last_error_at' => now(), 'last_error' => $result['message'] ?? null]);

        return response()->json($result);
    }

    // ── Regenerar el secreto de entrada ───────────────────────────────
    public function regenerateInboundSecret(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403, 'No autorizado para esta acción.');

        $secret = Integration::freshInboundSecret();
        $integration->update(['inbound_secret' => $secret]);

        return response()->json(['inbound_secret' => $secret, 'inbound_url' => $this->inboundUrl($integration)]);
    }

    // ── Bitácora ──────────────────────────────────────────────────────
    public function logs(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($request->user()->can('integrations.view'), 403, 'No autorizado para esta acción.');

        $logs = $integration->logs()->latest('id')->limit(50)->get()->map(fn ($l) => [
            'id'           => $l->id,
            'direction'    => $l->direction,
            'event_type'   => $l->event_type,
            'status'       => $l->status,
            'attempts'     => $l->attempts,
            'error'        => $l->error,
            'delivered_at' => $l->delivered_at?->toISOString(),
            'created_at'   => $l->created_at?->toISOString(),
        ]);

        return response()->json($logs);
    }

    // ── Eliminar la configuración de un alcance ───────────────────────
    public function destroy(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($request->user()->can('integrations.manage'), 403, 'No autorizado para esta acción.');
        $integration->delete();
        return response()->json(['message' => 'Integración eliminada.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Serializa una integración enmascarando los secretos. */
    private function serialize(Integration $i): array
    {
        $provider = $this->manager->provider($i->provider);
        $schema   = $provider ? $provider->configSchema() : [];

        $config  = [];
        $secrets = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            if ($field['secret'] ?? false) {
                $secrets[$key] = ! blank($i->conf($key)); // ¿ya guardado?
            } else {
                $config[$key] = $i->conf($key);
            }
        }

        return [
            'id'            => $i->id,
            'provider'      => $i->provider,
            'client_id'     => $i->client_id,
            'client'        => $i->client ? ['id' => $i->client->id, 'name' => $i->client->name] : null,
            'is_active'     => $i->is_active,
            'configured'    => $provider ? $provider->isConfigured($i) : false,
            'config'        => $config,   // solo campos NO secretos
            'secrets_set'   => $secrets,  // {campo: bool} para los secretos
            'inbound_url'   => $this->inboundUrl($i),
            'inbound_secret' => $i->inbound_secret, // visible solo a quien administra
            'last_ok_at'    => $i->last_ok_at?->toISOString(),
            'last_error_at' => $i->last_error_at?->toISOString(),
            'last_error'    => $i->last_error,
        ];
    }

    private function inboundUrl(Integration $i): string
    {
        return rtrim(config('app.url'), '/') . '/api/integrations/' . $i->provider . '/inbound';
    }
}
