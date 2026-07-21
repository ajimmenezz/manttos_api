<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWebhook;
use App\Models\Client;
use App\Models\Site;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\WebhookEvent;
use App\Support\WebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de webhooks salientes por CLIENTE (y opcionalmente por SITIO). El alcance se
 * calca del patrón scopeByUser de ClientController: superadmin/admin ven todo; el
 * admin-cliente solo los de SUS clientes; el admin-sitio solo los de SUS sitios.
 */
class WebhookEndpointController extends Controller
{
    // ── Listado (scoped) ──────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.view'), 403, 'No autorizado para esta acción.');

        $endpoints = $this->scopeByUser($request, WebhookEndpoint::query())
            ->with(['client:id,name', 'site:id,name'])
            ->latest('id')
            ->get();

        return response()->json($endpoints->map(fn ($e) => $this->serialize($e)));
    }

    // ── Opciones para el formulario (clientes/sitios que puede elegir + catálogo) ──
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.view'), 403, 'No autorizado para esta acción.');

        $user = $request->user();
        $clientsQuery = Client::query()->orderBy('name');
        $sitesQuery   = Site::query()->orderBy('name');

        if (! $user->hasAnyRole(['superadmin', 'admin'])) {
            [$clientIds, $siteIds, $siteRequired] = $this->manageableScope($user);
            $clientsQuery->whereIn('id', $clientIds ?: [-1]);
            // admin-sitio solo elige entre sus sitios; admin-cliente, todos los de su cliente.
            $siteRequired
                ? $sitesQuery->whereIn('id', $siteIds ?: [-1])
                : $sitesQuery->whereIn('client_id', $clientIds ?: [-1]);
        }

        return response()->json([
            'clients' => $clientsQuery->get(['id', 'name']),
            'sites'   => $sitesQuery->get(['id', 'name', 'client_id']),
            'events'  => WebhookEvent::catalog(),
            // admin-sitio DEBE atar el webhook a un sitio suyo (no puede crear uno de todo el cliente).
            'site_required' => ! $user->hasAnyRole(['superadmin', 'admin', 'admin-cliente']),
        ]);
    }

    // ── Crear ─────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.manage'), 403, 'No autorizado para esta acción.');

        $data = $this->validatePayload($request);
        $this->authorizeScope($request, (int) $data['client_id'], $data['site_id'] ?? null);

        $secret = WebhookEndpoint::freshSecret();
        $endpoint = WebhookEndpoint::create([
            'client_id'   => $data['client_id'],
            'site_id'     => $data['site_id'] ?? null,
            'url'         => $data['url'],
            'secret'      => $secret,
            'description' => $data['description'] ?? null,
            'events'      => $data['events'] ?? [],
            'is_active'   => $data['is_active'] ?? true,
            'created_by'  => $request->user()->id,
        ]);

        // El secreto se muestra una sola vez (como el token de API): se necesita para firmar.
        return response()->json(array_merge(
            $this->serialize($endpoint->load(['client:id,name', 'site:id,name'])),
            ['secret' => $secret],
        ), 201);
    }

    // ── Ver ───────────────────────────────────────────────────────────
    public function show(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorizeView($request, $webhook);
        return response()->json($this->serialize($webhook->load(['client:id,name', 'site:id,name'])));
    }

    // ── Actualizar ────────────────────────────────────────────────────
    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.manage'), 403, 'No autorizado para esta acción.');
        $this->authorizeView($request, $webhook);

        $data = $request->validate([
            'url'         => ['sometimes', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events'      => ['sometimes', 'nullable', 'array'],
            'events.*'    => ['string', 'in:' . implode(',', WebhookEvent::keys())],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url'])) {
            if ($error = WebhookUrl::validationError($data['url'])) {
                throw ValidationException::withMessages(['url' => $error]);
            }
        }

        $webhook->update($data);

        return response()->json($this->serialize($webhook->fresh(['client:id,name', 'site:id,name'])));
    }

    // ── Eliminar ──────────────────────────────────────────────────────
    public function destroy(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.manage'), 403, 'No autorizado para esta acción.');
        $this->authorizeView($request, $webhook);

        $webhook->delete();

        return response()->json(['message' => 'Webhook eliminado.']);
    }

    // ── Regenerar el secreto ──────────────────────────────────────────
    public function regenerateSecret(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.manage'), 403, 'No autorizado para esta acción.');
        $this->authorizeView($request, $webhook);

        $secret = WebhookEndpoint::freshSecret();
        $webhook->update(['secret' => $secret]);

        return response()->json(['message' => 'Secreto regenerado.', 'secret' => $secret]);
    }

    // ── Enviar una prueba (ping) ──────────────────────────────────────
    public function test(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        abort_unless($request->user()->can('webhooks.manage'), 403, 'No autorizado para esta acción.');
        $this->authorizeView($request, $webhook);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhook->id,
            'event_type'          => 'ping',
            'payload'             => [
                'message'   => 'Webhook de prueba desde el Sistema de Mantenimientos.',
                'client_id' => $webhook->client_id,
                'site_id'   => $webhook->site_id,
                'sent_by'   => ['id' => $request->user()->id, 'name' => $request->user()->name],
            ],
            'status'              => 'pending',
        ]);

        SendWebhook::dispatch($delivery->id);

        return response()->json(['message' => 'Prueba enviada; revisa la bitácora.', 'delivery_id' => $delivery->id]);
    }

    // ── Bitácora de entregas ──────────────────────────────────────────
    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorizeView($request, $webhook);

        $deliveries = $webhook->deliveries()->latest('id')->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id'              => $d->id,
            'event_type'      => $d->event_type,
            'status'          => $d->status,
            'attempts'        => $d->attempts,
            'response_status' => $d->response_status,
            'error'           => $d->error,
            'delivered_at'    => $d->delivered_at?->toISOString(),
            'created_at'      => $d->created_at?->toISOString(),
        ]);

        return response()->json($deliveries);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'client_id'   => ['required', 'integer', 'exists:clients,id'],
            'site_id'     => ['nullable', 'integer', 'exists:sites,id'],
            'url'         => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:255'],
            'events'      => ['nullable', 'array'],
            'events.*'    => ['string', 'in:' . implode(',', WebhookEvent::keys())],
            'is_active'   => ['boolean'],
        ]);

        if ($error = WebhookUrl::validationError($data['url'])) {
            throw ValidationException::withMessages(['url' => $error]);
        }

        // El sitio, si se indica, debe pertenecer al cliente elegido.
        if (! empty($data['site_id'])) {
            $ok = Site::where('id', $data['site_id'])->where('client_id', $data['client_id'])->exists();
            abort_unless($ok, 422, 'El sitio no pertenece al cliente elegido.');
        }

        return $data;
    }

    /** Filtra los webhooks visibles según el rol (patrón scopeByUser de ClientController). */
    private function scopeByUser(Request $request, $query)
    {
        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return $query;
        }
        if ($user->hasRole('admin-cliente')) {
            return $query->whereIn('client_id', $user->clientsAsAdmin()->pluck('clients.id'));
        }
        if ($user->hasRole('admin-sitio')) {
            return $query->whereIn('site_id', $user->sitesAsAdmin()->pluck('sites.id'));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Clientes/sitios que el usuario puede administrar.
     * @return array{0: array<int,int>|null, 1: array<int,int>|null, 2: bool}  [clientIds, siteIds, siteRequired]
     */
    private function manageableScope($user): array
    {
        if ($user->hasRole('admin-cliente')) {
            return [$user->clientsAsAdmin()->pluck('clients.id')->all(), null, false];
        }
        if ($user->hasRole('admin-sitio')) {
            $siteIds   = $user->sitesAsAdmin()->pluck('sites.id')->all();
            $clientIds = $user->sitesAsAdmin()->pluck('client_id')->unique()->values()->all();
            return [$clientIds, $siteIds, true];
        }

        return [[], [], true];
    }

    /** Verifica que el usuario pueda crear un webhook para ese cliente/sitio. */
    private function authorizeScope(Request $request, int $clientId, ?int $siteId): void
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return;
        }

        [$clientIds, $siteIds, $siteRequired] = $this->manageableScope($user);

        abort_unless(in_array($clientId, $clientIds ?? [], true), 403, 'No administras este cliente.');

        if ($siteRequired) {
            abort_if($siteId === null, 422, 'Debes elegir un sitio.');
            abort_unless(in_array($siteId, $siteIds ?? [], true), 403, 'No administras este sitio.');
        }
    }

    /** Autoriza ver/editar un webhook existente (misma regla que el listado). */
    private function authorizeView(Request $request, WebhookEndpoint $webhook): void
    {
        $ok = $this->scopeByUser($request, WebhookEndpoint::whereKey($webhook->id))->exists();
        abort_unless($ok, 403, 'No tienes acceso a este webhook.');
    }

    private function serialize(WebhookEndpoint $e): array
    {
        return [
            'id'              => $e->id,
            'client'          => $e->client ? ['id' => $e->client->id, 'name' => $e->client->name] : null,
            'site'            => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            'url'             => $e->url,
            'description'     => $e->description,
            'events'          => $e->events ?: [],   // [] = todos
            'is_active'       => $e->is_active,
            'last_success_at' => $e->last_success_at?->toISOString(),
            'last_failure_at' => $e->last_failure_at?->toISOString(),
            'created_at'      => $e->created_at?->toISOString(),
        ];
    }
}
