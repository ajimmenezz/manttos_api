<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\DevicePlacement;
use App\Models\Directory;
use App\Models\FloorPlan;
use App\Models\FloorPlanDirectoryFilter;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planos (floor plans) de un sitio. El plano (imagen) pertenece al SITIO y es
 * compartido por todos los sistemas; el sembrado de dispositivos (placements)
 * es por directorio/sistema. Cada directorio coloca sus propios dispositivos
 * sobre el mismo plano.
 */
class FloorPlanController extends Controller
{
    private function authorizeSiteAccess(Request $request, Client $client, Site $site): void
    {
        abort_unless($site->client_id === $client->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        // El admin-cliente solo accede a sitios de SUS clientes (antes pasaba sin verificar → fuga).
        if ($user->hasRole('admin-cliente') && $user->clientsAsAdmin()->where('clients.id', $client->id)->exists()) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        abort(403, 'No tienes acceso a este sitio.');
    }

    public function index(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('floor-plans.view'), 403, 'No autorizado para esta acción.');

        $plans = $site->floorPlans()
            ->withCount('placements')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FloorPlan $p) => $this->serialize($p));

        return response()->json($plans);
    }

    public function store(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('floor-plans.manage'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'image_url'    => 'required|string|max:2048',
            'image_width'  => 'nullable|integer|min:1',
            'image_height' => 'nullable|integer|min:1',
            'sort_order'   => 'nullable|integer|min:0',
        ]);

        $plan = $site->floorPlans()->create([
            ...$data,
            'is_active'  => true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message'    => 'Plano creado correctamente.',
            'floor_plan' => $this->serialize($plan),
        ], 201);
    }

    /**
     * Detalle del plano con sus dispositivos sembrados. Acepta ?directory_id=
     * para filtrar el sembrado a un solo sistema (opcional).
     */
    public function show(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.view'), 403, 'No autorizado para esta acción.');

        $placements = $floorPlan->placements()
            ->with('device:id,directory_id,name,device_type,status,custom_fields')
            ->when($request->filled('directory_id'), fn ($q) => $q->whereHas(
                'device',
                fn ($d) => $d->where('directory_id', $request->integer('directory_id'))
            ))
            ->get()
            ->map(fn ($pl) => [
                'device_id'    => $pl->device_id,
                'directory_id' => $pl->device?->directory_id,
                'x'            => (float) $pl->x,
                'y'            => (float) $pl->y,
                'name'         => $pl->device?->name,
                'device_type'  => $pl->device?->device_type,
                'status'       => $pl->device?->status,
                'custom_fields'=> $pl->device?->custom_fields,
            ]);

        // Filtro fijo del plano para este directorio (si se pidió directory_id)
        $directoryFilters = (object) [];
        if ($request->filled('directory_id')) {
            $row = FloorPlanDirectoryFilter::where('floor_plan_id', $floorPlan->id)
                ->where('directory_id', $request->integer('directory_id'))
                ->first();
            if ($row && is_array($row->filters) && !empty($row->filters)) {
                $directoryFilters = $row->filters;
            }
        }

        return response()->json([
            ...$this->serialize($floorPlan),
            'placements'        => $placements,
            'directory_filters' => $directoryFilters,
        ]);
    }

    /**
     * Guarda el filtro fijo del plano para un directorio: los dispositivos que
     * "pertenecen" a este plano según campos del directorio. `filters` =
     * { field_key: ["v1","v2"], ... }. Vacío = sin filtro fijo (se elimina la fila).
     */
    public function saveDirectoryFilter(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.place'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'directory_id' => 'required|integer',
            'filters'      => 'nullable|array',
            'filters.*'    => 'array',   // cada campo → lista de valores
        ]);

        abort_unless(
            Directory::where('id', $data['directory_id'])->where('site_id', $site->id)->exists(),
            404, 'El directorio no pertenece a este sitio.'
        );

        // Limpia: descarta campos con lista vacía y valores vacíos.
        $filters = collect($data['filters'] ?? [])
            ->map(fn ($vals) => collect($vals)->filter(fn ($v) => $v !== '' && $v !== null)->values()->all())
            ->filter(fn ($vals) => count($vals) > 0)
            ->all();

        if (empty($filters)) {
            FloorPlanDirectoryFilter::where('floor_plan_id', $floorPlan->id)
                ->where('directory_id', $data['directory_id'])->delete();
            return response()->json(['message' => 'Filtro del plano quitado.', 'directory_filters' => (object) []]);
        }

        FloorPlanDirectoryFilter::updateOrCreate(
            ['floor_plan_id' => $floorPlan->id, 'directory_id' => $data['directory_id']],
            ['filters' => $filters],
        );

        return response()->json(['message' => 'Filtro del plano guardado.', 'directory_filters' => $filters]);
    }

    public function update(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.manage'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'image_url'    => 'sometimes|required|string|max:2048',
            'image_width'  => 'nullable|integer|min:1',
            'image_height' => 'nullable|integer|min:1',
            'sort_order'   => 'nullable|integer|min:0',
        ]);

        $floorPlan->update($data);

        return response()->json([
            'message'    => 'Plano actualizado correctamente.',
            'floor_plan' => $this->serialize($floorPlan),
        ]);
    }

    public function toggleStatus(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.manage'), 403, 'No autorizado para esta acción.');

        $floorPlan->update(['is_active' => ! $floorPlan->is_active]);
        $status = $floorPlan->is_active ? 'activado' : 'desactivado';

        return response()->json([
            'message'    => "Plano {$status}.",
            'floor_plan' => $this->serialize($floorPlan),
        ]);
    }

    public function destroy(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.manage'), 403, 'No autorizado para esta acción.');

        // cascade elimina los placements; la imagen se queda en disco (compartible / por limpieza aparte)
        $floorPlan->delete();

        return response()->json(['message' => 'Plano eliminado.']);
    }

    /**
     * Guarda (upsert) el sembrado de dispositivos sobre el plano. No borra
     * placements ausentes — eliminar es explícito vía deletePlacement.
     * Valida que cada dispositivo pertenezca a un directorio de este sitio.
     */
    public function savePlacements(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.place'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'placements'             => 'required|array|min:1',
            'placements.*.device_id' => 'required|integer',
            'placements.*.x'         => 'required|numeric|min:0|max:1',
            'placements.*.y'         => 'required|numeric|min:0|max:1',
        ]);

        $deviceIds = collect($data['placements'])->pluck('device_id')->unique();

        // Solo dispositivos que cuelgan de un directorio de este sitio
        $validIds = Device::whereIn('id', $deviceIds)
            ->whereHas('directory', fn ($q) => $q->where('site_id', $site->id))
            ->pluck('id')
            ->flip();

        // Integridad: un dispositivo solo puede estar en UN plano. Si alguno ya
        // está sembrado en otro plano del sitio, se rechaza el guardado.
        $conflict = DevicePlacement::whereIn('device_id', $deviceIds)
            ->where('floor_plan_id', '!=', $floorPlan->id)
            ->exists();
        abort_if($conflict, 422, 'Uno o más dispositivos ya están ubicados en otro plano de este sitio. Quítalos de ese plano antes de moverlos aquí.');

        $userId = $request->user()->id;

        foreach ($data['placements'] as $p) {
            if (! $validIds->has($p['device_id'])) continue;

            $floorPlan->placements()->updateOrCreate(
                ['device_id' => $p['device_id']],
                ['x' => $p['x'], 'y' => $p['y'], 'created_by' => $userId],
            );
        }

        return response()->json(['message' => 'Sembrado guardado.']);
    }

    public function deletePlacement(Request $request, Client $client, Site $site, FloorPlan $floorPlan, Device $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.place'), 403, 'No autorizado para esta acción.');

        $floorPlan->placements()->where('device_id', $device->id)->delete();

        return response()->json(['message' => 'Dispositivo quitado del plano.']);
    }

    /**
     * Regresa TODOS los dispositivos a la lista (borra el sembrado del plano).
     * Scopeado por directorio: solo afecta a los dispositivos de ese sistema,
     * no a los de otros sistemas que comparten el mismo plano.
     */
    public function clearPlacements(Request $request, Client $client, Site $site, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($floorPlan->site_id === $site->id, 404);
        abort_unless($request->user()->can('floor-plans.place'), 403, 'No autorizado para esta acción.');

        $q = $floorPlan->placements();
        if ($request->filled('directory_id')) {
            $dirId = $request->integer('directory_id');
            $q->whereHas('device', fn ($d) => $d->where('directory_id', $dirId));
        }
        $count = $q->count();
        $q->delete();

        return response()->json(['message' => 'Dispositivos regresados a la lista.', 'cleared' => $count]);
    }

    /**
     * Dispositivos de un directorio que ya están sembrados en algún plano del sitio
     * (para que el editor no permita sembrar el mismo dispositivo en dos planos).
     */
    public function placedDevices(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('floor-plans.view'), 403, 'No autorizado para esta acción.');

        $rows = DevicePlacement::query()
            ->join('floor_plans', 'device_placements.floor_plan_id', '=', 'floor_plans.id')
            ->join('devices', 'device_placements.device_id', '=', 'devices.id')
            ->where('floor_plans.site_id', $site->id)
            ->when($request->filled('directory_id'), fn ($q) => $q->where('devices.directory_id', $request->integer('directory_id')))
            ->get([
                'device_placements.device_id',
                'device_placements.floor_plan_id',
                'floor_plans.name as plan_name',
            ]);

        return response()->json($rows);
    }

    private function serialize(FloorPlan $p): array
    {
        return [
            'id'              => $p->id,
            'site_id'         => $p->site_id,
            'name'            => $p->name,
            'image_url'       => $p->image_url,
            'image_width'     => $p->image_width,
            'image_height'    => $p->image_height,
            'sort_order'      => $p->sort_order,
            'is_active'       => $p->is_active,
            'placements_count'=> $p->placements_count ?? $p->placements()->count(),
            'created_at'      => $p->created_at,
        ];
    }
}
