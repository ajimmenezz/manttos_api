<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Device;
use App\Models\Directory;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceActivityController extends Controller
{
    private function authorizeAccess(Maintenance $maintenance): void
    {
        $user = request()->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-sitio') &&
            $user->sitesAsAdmin()->where('sites.id', $maintenance->site_id)->exists()
        ) return;

        if ($user->hasRole('admin-cliente')) {
            $maintenance->loadMissing('site');
            if ($user->clientsAsAdmin()->where('clients.id', $maintenance->site->client_id)->exists()) return;
        }

        if ($user->hasRole('ingeniero') &&
            $maintenance->engineers()->where('users.id', $user->id)->exists()
        ) return;

        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    /** GET /maintenances/{maintenance}/activity-types
     *  Tipos de actividad asociados al sistema del mantenimiento
     */
    public function activityTypes(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $types = Catalog::ofType(Catalog::TYPE_ACTIVITY_TYPE)
            ->whereExists(fn ($q) => $q
                ->from('activity_type_systems')
                ->whereColumn('activity_type_id', 'catalogs.id')
                ->where('system_id', $maintenance->catalog_id)
            )
            ->get(['id', 'label']);

        return response()->json($types);
    }

    /** GET /maintenances/{maintenance}/activity-devices
     *  Directorios + dispositivos del sistema (sin conteos — carga rápida)
     */
    public function devices(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $systemId = $maintenance->catalog_id;

        $directories = Directory::with([
            'devices' => fn ($q) => $q->where('is_active', true)->orderBy('name'),
        ])
            ->where('site_id', $maintenance->site_id)
            ->where('catalog_id', $systemId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $result = $directories->map(function ($dir) {
            $devices = $dir->devices->map(fn ($dev) => [
                'id'            => $dev->id,
                'name'          => $dev->name,
                'device_type'   => $dev->device_type,
                'custom_fields' => $dev->custom_fields ?? [],
            ])->values();

            return ['id' => $dir->id, 'name' => $dir->display_name, 'devices' => $devices];
        })->filter(fn ($d) => count($d['devices']) > 0)->values();

        return response()->json(['directories' => $result]);
    }

    /** GET /maintenances/{maintenance}/activity-counts
     *  Conteo de actividades por (device_id → activity_type_id) — carga lazy
     */
    public function activityCounts(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $rows = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->select('device_id', 'activity_type_id', DB::raw('count(*) as cnt'))
            ->groupBy('device_id', 'activity_type_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->device_id][$row->activity_type_id] = (int) $row->cnt;
        }

        return response()->json($result);
    }

    /** POST /maintenances/{maintenance}/activities */
    public function store(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para registrar actividades.');

        $data = $request->validate([
            'device_id'        => 'required|integer|exists:devices,id',
            'activity_type_id' => 'required|integer|exists:catalogs,id',
            'field_values'     => 'nullable|array',
            'performed_at'     => 'nullable|date',
        ]);

        // Validar que el dispositivo pertenece al sistema del mantenimiento
        $device = Device::findOrFail($data['device_id']);
        $dir    = Directory::findOrFail($device->directory_id);
        abort_unless(
            $dir->site_id === $maintenance->site_id && $dir->catalog_id === $maintenance->catalog_id,
            422,
            'El dispositivo no pertenece al sistema de este mantenimiento.'
        );

        // Validar que el tipo de actividad está asociado al sistema
        $isLinked = DB::table('activity_type_systems')
            ->where('activity_type_id', $data['activity_type_id'])
            ->where('system_id', $maintenance->catalog_id)
            ->exists();
        abort_unless($isLinked, 422, 'El tipo de actividad no aplica para este sistema.');

        $activity = MaintenanceActivity::create([
            'maintenance_id'   => $maintenance->id,
            'device_id'        => $data['device_id'],
            'activity_type_id' => $data['activity_type_id'],
            'user_id'          => $request->user()->id,
            'field_values'     => $data['field_values'] ?? [],
            'performed_at'     => $data['performed_at'] ?? now(),
        ]);

        return response()->json(
            $activity->load(['activityType:id,label', 'user:id,name']),
            201
        );
    }

    /** GET /maintenances/{maintenance}/devices/{device}/activities
     *  Historial de actividades de un dispositivo en este mantenimiento
     */
    public function deviceActivities(Maintenance $maintenance, Device $device): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $activities = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->where('device_id', $device->id)
            ->with(['activityType:id,label', 'user:id,name'])
            ->orderByDesc('performed_at')
            ->get();

        return response()->json($activities);
    }

    /** GET /maintenances/{maintenance}/log?date_from=Y-m-d&date_to=Y-m-d
     *  Todas las actividades del mantenimiento en el rango de fechas (por performed_at)
     */
    public function log(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        $query = MaintenanceActivity::where('maintenance_id', $maintenance->id);
        if (!empty($validated['date_from'])) {
            $query->whereDate('performed_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('performed_at', '<=', $validated['date_to']);
        }
        $activities = $query->with([
                'activityType:id,label',
                'user:id,name',
                'device:id,name,device_type,custom_fields,directory_id',
                'device.directory:id,name',
            ])
            ->orderBy('performed_at')
            ->orderBy('id')
            ->get();


        $result = $activities->map(fn ($act) => [
            'id'            => $act->id,
            'performed_at'  => $act->performed_at,
            'created_at'    => $act->created_at,
            'field_values'  => $act->field_values ?? [],
            'activity_type' => $act->activityType,
            'user'          => $act->user,
            'device'        => $act->device ? [
                'id'             => $act->device->id,
                'name'           => $act->device->name,
                'device_type'    => $act->device->device_type,
                'custom_fields'  => $act->device->custom_fields ?? [],
                'directory_name' => $act->device->directory?->name,
            ] : null,
        ]);

        return response()->json($result);
    }

    /** PUT /maintenances/{maintenance}/activities/{activity} */
    public function update(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($activity->maintenance_id === $maintenance->id, 404);
        $this->authorizeAccess($maintenance);
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para editar actividades.');

        $data = $request->validate([
            'field_values' => 'nullable|array',
            'performed_at' => 'nullable|date',
        ]);

        $activity->update([
            'field_values' => $data['field_values'] ?? $activity->field_values,
            'performed_at' => $data['performed_at'] ?? $activity->performed_at,
        ]);

        return response()->json(
            $activity->fresh()->load(['activityType:id,label', 'user:id,name'])
        );
    }

    /** DELETE /maintenances/{maintenance}/activities/{activity} */
    public function destroy(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($activity->maintenance_id === $maintenance->id, 404);
        abort_unless(
            $request->user()->can('maintenances.record-activity') || $request->user()->id === $activity->user_id,
            403,
            'Sin permiso para eliminar esta actividad.'
        );

        $activity->delete();

        return response()->json(['message' => 'Actividad eliminada.']);
    }
}
