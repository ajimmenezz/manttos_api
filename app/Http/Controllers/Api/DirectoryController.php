<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Client;
use App\Models\Directory;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    private function authorizeSiteAccess(Request $request, Client $client, Site $site): void
    {
        abort_unless($site->client_id === $client->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin', 'admin-cliente'])) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        abort(403, 'No tienes acceso a este sitio.');
    }

    /**
     * Lista plana de todos los directorios accesibles por el usuario
     * (sección Operaciones → Directorios). Mismo scope por rol que los sitios:
     * cada usuario solo ve los directorios de los sitios a los que tiene acceso.
     */
    public function all(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Directory::query()
            ->join('sites', 'directories.site_id', '=', 'sites.id')
            ->join('clients', 'sites.client_id', '=', 'clients.id')
            // Oculta directorios de sitios/clientes archivados (los join crudos no aplican
            // el scope de SoftDeletes, así que se filtra explícitamente).
            ->whereNull('sites.deleted_at')
            ->whereNull('clients.deleted_at')
            ->with(['system', 'site.client'])
            ->withCount('devices')
            ->select('directories.*')
            ->when($request->filled('is_active'), fn ($q) => $q->where('directories.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('clients.name')
            ->orderBy('sites.name')
            ->orderBy('directories.id');

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            // sin restricción — ven todo
        } elseif ($user->hasRole('admin-cliente')) {
            $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
            $query->whereIn('sites.client_id', $clientIds);
        } elseif ($user->hasRole('admin-sitio')) {
            $siteIds = $user->sitesAsAdmin()->pluck('sites.id');
            $query->whereIn('directories.site_id', $siteIds);
        } else {
            // ingeniero / técnico u otros: no gestionan directorios
            return response()->json(['data' => [], 'total' => 0]);
        }

        $paginated = $query->paginate($request->per_page ?? 1000);

        $paginated->getCollection()->transform(fn (Directory $d) => [
            'id'            => $d->id,
            'client_id'     => $d->site?->client_id,
            'client_name'   => $d->site?->client?->name,
            'site_id'       => $d->site_id,
            'site_name'     => $d->site?->name,
            'site_code'     => $d->site?->code,
            'catalog_id'    => $d->catalog_id,
            'system_label'  => $d->system?->label,
            'display_name'  => $d->display_name,
            'devices_count' => $d->devices_count ?? 0,
            'is_active'     => $d->is_active,
        ]);

        return response()->json($paginated);
    }

    public function index(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);

        $directories = $site->directories()
            ->with('system')
            ->withCount('devices')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($d) => $this->serialize($d));

        return response()->json($directories);
    }

    public function store(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);

        $data = $request->validate([
            'catalog_id' => 'required|exists:catalogs,id',
            'name'       => 'nullable|string|max:255',
            'notes'      => 'nullable|string',
        ]);

        // Verificar que el catalog_id es del tipo 'system'
        $catalog = Catalog::findOrFail($data['catalog_id']);
        abort_if($catalog->type !== Catalog::TYPE_SYSTEM, 422, 'El catálogo debe ser de tipo sistema.');

        // Verificar unicidad site + sistema
        $exists = $site->directories()->where('catalog_id', $data['catalog_id'])->exists();
        abort_if($exists, 422, 'Este sitio ya tiene un directorio para ese sistema.');

        $directory = $site->directories()->create([
            ...$data,
            'is_active'  => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message'   => 'Directorio creado correctamente.',
            'directory' => $this->serialize($directory->load('system')),
        ], 201);
    }

    public function show(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($directory->site_id === $site->id, 404);

        return response()->json($this->serialize($directory->load('system')));
    }

    public function update(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($directory->site_id === $site->id, 404);

        $data = $request->validate([
            'name'  => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $directory->update($data);

        return response()->json([
            'message'   => 'Directorio actualizado correctamente.',
            'directory' => $this->serialize($directory->load('system')),
        ]);
    }

    public function toggleStatus(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($directory->site_id === $site->id, 404);

        $directory->update(['is_active' => ! $directory->is_active]);
        $status = $directory->is_active ? 'activado' : 'desactivado';

        return response()->json([
            'message'   => "Directorio {$status}.",
            'directory' => $this->serialize($directory->load('system')),
        ]);
    }

    private function serialize(Directory $d): array
    {
        return [
            'id'           => $d->id,
            'site_id'      => $d->site_id,
            'catalog_id'   => $d->catalog_id,
            'system_label' => $d->system?->label,
            'name'         => $d->name,
            'display_name' => $d->display_name,
            'notes'        => $d->notes,
            'is_active'    => $d->is_active,
            'devices_count'=> $d->devices_count ?? $d->devices()->count(),
            'created_at'   => $d->created_at,
        ];
    }
}
