<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /**
     * Restringe qué sitios puede ver/operar el usuario dentro de un cliente.
     * - superadmin / admin / admin-cliente → todos los sitios del cliente
     * - admin-sitio → solo los sitios asignados dentro de ese cliente
     */
    private function scopeSites(Request $request, Client $client, $query = null)
    {
        $query ??= $client->sites();
        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin', 'admin-cliente'])) {
            return $query;
        }

        if ($user->hasRole('admin-sitio')) {
            $assignedIds = $user->sitesAsAdmin()
                ->where('client_id', $client->id)
                ->pluck('sites.id');

            return $query->whereIn('sites.id', $assignedIds);
        }

        if ($user->hasRole('ingeniero')) {
            // Ingeniero del cliente → todos sus sitios; si no, solo los sitios asignados.
            if ($user->clientsAsEngineer()->where('clients.id', $client->id)->exists()) {
                return $query;
            }
            $assignedIds = $user->sitesAsEngineer()
                ->where('sites.client_id', $client->id)
                ->pluck('sites.id');
            return $query->whereIn('sites.id', $assignedIds);
        }

        return $query->whereRaw('1 = 0');
    }

    private function authorizeSiteAccess(Request $request, Client $client, Site $site): void
    {
        abort_unless($site->client_id === $client->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin', 'admin-cliente'])) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        abort(403, 'No tienes acceso a este sitio.');
    }

    /**
     * Lista compacta (id, name, code) de sitios activos de un cliente — para selects.
     * Aplica el mismo scope de rol que los otros endpoints.
     */
    public function compact(Request $request, Client $client): JsonResponse
    {
        $query = $client->sites()->where('is_active', true)->orderBy('name');
        $sites = $this->scopeSites($request, $client, $query)->get(['id', 'name', 'code']);
        return response()->json($sites);
    }

    /**
     * Lista plana de todos los sitios accesibles por el usuario (para el panel de "Mis sitios").
     */
    public function all(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Site::with('client')
            // Oculta sitios cuyo cliente está archivado (cascada lógica).
            ->whereHas('client')
            // ?archived=1 → solo sitios archivados; por defecto solo activos.
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->search, fn ($q) => $q->where('sites.name', 'ilike', "%{$request->search}%")
                ->orWhere('sites.city', 'ilike', "%{$request->search}%"))
            ->when($request->filled('is_active'), fn ($q) => $q->where('sites.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('sites.name');

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            // sin restricción
        } elseif ($user->hasRole('admin-cliente')) {
            $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
            $query->whereIn('sites.client_id', $clientIds);
        } elseif ($user->hasRole('admin-sitio')) {
            $siteIds = $user->sitesAsAdmin()->pluck('sites.id');
            $query->whereIn('sites.id', $siteIds);
        } else {
            return response()->json(['data' => [], 'total' => 0]);
        }

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    public function index(Request $request, Client $client): JsonResponse
    {
        $baseQuery = $client->sites()
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%")
                ->orWhere('code', 'ilike', "%{$request->search}%")
                ->orWhere('city', 'ilike', "%{$request->search}%"))
            ->when($request->filled('type'),      fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name');

        $sites = $this->scopeSites($request, $client, $baseQuery)->paginate($request->per_page ?? 20);

        return response()->json($sites);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        // Guard de alcance (hueco previo): el usuario debe poder administrar el cliente padre.
        // Mismo criterio que ClientController::authorizeClientAccess.
        $user = $request->user();
        if (! $user->hasAnyRole(['superadmin', 'admin'])
            && ! ($user->hasRole('admin-cliente') && $client->admins()->where('users.id', $user->id)->exists())
            && ! ($user->hasRole('admin-sitio') && $user->sitesAsAdmin()->where('client_id', $client->id)->exists())
        ) {
            abort(403, 'No tienes acceso a este cliente.');
        }
        abort_unless($user->can('sites.create'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'code'    => 'nullable|string|max:50',
            'type'    => 'required|string|max:50',
            'address' => 'nullable|string|max:500',
            'city'    => 'nullable|string|max:100',
            'state'   => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'notes'   => 'nullable|string',
        ]);

        $site = $client->sites()->create([
            ...$data,
            'country'    => $data['country'] ?? 'México',
            'created_by' => $request->user()->id,
            'is_active'  => true,
        ]);

        return response()->json(['message' => 'Sitio creado correctamente.', 'site' => $site->load('client')], 201);
    }

    public function show(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);

        return response()->json($site->load('client'));
    }

    public function update(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('sites.edit'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'code'    => 'nullable|string|max:50',
            'type'    => 'required|string|max:50',
            'address' => 'nullable|string|max:500',
            'city'    => 'nullable|string|max:100',
            'state'   => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'notes'   => 'nullable|string',
        ]);

        $site->update($data);

        return response()->json(['message' => 'Sitio actualizado correctamente.', 'site' => $site->load('client')]);
    }

    // Archivar = baja lógica reversible (solo superadmin). Sus directorios/dispositivos/
    // mantenimientos/eventos quedan ocultos (los listados filtran por whereHas del sitio).
    public function destroy(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($request->user()->can('sites.archive'), 403, 'No autorizado para esta acción.');
        abort_unless($site->client_id === $client->id, 404);
        $site->delete();

        return response()->json(['message' => 'Sitio archivado.']);
    }

    public function restore(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($request->user()->can('sites.archive'), 403, 'No autorizado para esta acción.');
        abort_unless($site->client_id === $client->id, 404);
        $site->restore();

        return response()->json(['message' => 'Sitio restaurado.', 'site' => $site->load('client')]);
    }

    public function toggleStatus(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('sites.toggle-status'), 403, 'No autorizado para esta acción.');
        $site->update(['is_active' => ! $site->is_active]);
        $status = $site->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Sitio {$status}.", 'site' => $site]);
    }
}
