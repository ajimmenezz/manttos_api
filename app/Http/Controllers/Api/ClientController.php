<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Aplica el scope de visibilidad según el rol del usuario:
     * - superadmin / admin → todos los clientes
     * - admin-cliente       → solo los clientes asignados
     * - admin-sitio         → los clientes de los sitios que administra
     */
    private function scopeByUser(Request $request, $query)
    {
        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return $query;
        }

        if ($user->hasRole('admin-cliente')) {
            return $query->whereHas('admins', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($user->hasRole('admin-sitio')) {
            // Puede ver los clientes de sus sitios asignados
            $clientIds = $user->sitesAsAdmin()->pluck('client_id')->unique();
            return $query->whereIn('id', $clientIds);
        }

        if ($user->hasRole('ingeniero')) {
            // Clientes donde atiende: asignado al cliente ∪ cliente de un sitio asignado.
            $direct  = $user->clientsAsEngineer()->pluck('clients.id');
            $viaSites = $user->sitesAsEngineer()->pluck('sites.client_id');
            return $query->whereIn('id', $direct->merge($viaSites)->unique());
        }

        // Cualquier otro rol no ve clientes
        return $query->whereRaw('1 = 0');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Client::withCount('sites')
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%")
                ->orWhere('short_name', 'ilike', "%{$request->search}%"))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name');

        $clients = $this->scopeByUser($request, $query)->paginate($request->per_page ?? 15);

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
            'rfc'        => 'nullable|string|max:13',
            'industry'   => 'nullable|string|max:100',
            'notes'      => 'nullable|string',
        ]);

        $client = Client::create([
            ...$data,
            'created_by' => $request->user()->id,
            'is_active'  => true,
        ]);

        return response()->json(['message' => 'Cliente creado correctamente.', 'client' => $client], 201);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        return response()->json($client->loadCount('sites'));
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
            'rfc'        => 'nullable|string|max:13',
            'industry'   => 'nullable|string|max:100',
            'notes'      => 'nullable|string',
            // Nomenclatura de folio de eventos por cliente
            'event_folio_config'               => 'nullable|array',
            'event_folio_config.prefix'        => 'nullable|string|max:12',
            'event_folio_config.include_year'  => 'boolean',
            'event_folio_config.pad'           => 'nullable|integer|min:1|max:8',
            'event_folio_config.reset_yearly'  => 'boolean',
        ]);

        $client->update($data);

        return response()->json(['message' => 'Cliente actualizado correctamente.', 'client' => $client]);
    }

    public function destroy(Client $client): JsonResponse
    {
        if ($client->sites()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar un cliente que tiene sitios registrados. Desactívalo en su lugar.',
            ], 422);
        }

        $client->delete();

        return response()->json(['message' => 'Cliente eliminado correctamente.']);
    }

    public function toggleStatus(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        $client->update(['is_active' => ! $client->is_active]);
        $status = $client->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Cliente {$status}.", 'client' => $client]);
    }

    public function all(Request $request): JsonResponse
    {
        $query = Client::where('is_active', true)->orderBy('name');
        $clients = $this->scopeByUser($request, $query)->get(['id', 'name', 'short_name']);

        return response()->json($clients);
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-cliente') && $client->admins()->where('users.id', $user->id)->exists()) return;

        if ($user->hasRole('admin-sitio') && $user->sitesAsAdmin()->where('client_id', $client->id)->exists()) return;

        abort(403, 'No tienes acceso a este cliente.');
    }
}
