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
