<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceSchedule;
use App\Models\Maintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceScheduleController extends Controller
{
    /**
     * Alcance por-usuario del mantenimiento (antes solo se validaba el permiso →
     * un ingeniero podía programar dispositivos en mantenimientos ajenos).
     */
    private function authorizeMaintenanceAccess(Request $request, Maintenance $maintenance): void
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        if ($user->hasRole('admin-sitio') && $user->sitesAsAdmin()->where('sites.id', $maintenance->site_id)->exists()) return;
        if ($user->hasRole('admin-cliente')) {
            $maintenance->loadMissing('site');
            if ($user->clientsAsAdmin()->where('clients.id', $maintenance->site->client_id)->exists()) return;
        }
        if ($user->hasRole('ingeniero') && $maintenance->engineers()->where('users.id', $user->id)->exists()) return;
        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    public function index(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.view'), 403, 'Sin permiso para ver la programación.');
        $this->authorizeMaintenanceAccess($request, $maintenance);

        $schedules = DeviceSchedule::where('maintenance_id', $maintenance->id)
            ->get(['id', 'device_id', 'scheduled_date']);

        return response()->json($schedules);
    }

    public function store(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.schedule-devices'), 403, 'Sin permiso para programar dispositivos.');
        $this->authorizeMaintenanceAccess($request, $maintenance);

        $data = $request->validate([
            'device_ids'     => 'required|array|min:1',
            'device_ids.*'   => 'required|integer|exists:devices,id',
            'scheduled_date' => 'required|date|after:today',
        ]);

        $now     = now();
        $userId  = $request->user()->id;
        $records = array_map(fn ($id) => [
            'maintenance_id' => $maintenance->id,
            'device_id'      => $id,
            'scheduled_date' => $data['scheduled_date'],
            'created_by'     => $userId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $data['device_ids']);

        // Upsert: si ya existe la combinación maintenance+device, actualiza la fecha
        DeviceSchedule::upsert(
            $records,
            ['maintenance_id', 'device_id'],
            ['scheduled_date', 'updated_at']
        );

        $count = count($records);
        return response()->json(['message' => "{$count} dispositivo(s) programados."], 201);
    }

    public function update(Request $request, Maintenance $maintenance, DeviceSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.schedule-devices'), 403, 'Sin permiso para reprogramar.');
        $this->authorizeMaintenanceAccess($request, $maintenance);
        abort_unless($schedule->maintenance_id === $maintenance->id, 404);

        $data = $request->validate([
            'scheduled_date' => 'required|date|after:today',
        ]);

        $schedule->update($data);

        return response()->json(['message' => 'Programación actualizada.', 'schedule' => $schedule]);
    }

    public function destroy(Request $request, Maintenance $maintenance, DeviceSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.schedule-devices'), 403, 'Sin permiso para desprogramar.');
        $this->authorizeMaintenanceAccess($request, $maintenance);
        abort_unless($schedule->maintenance_id === $maintenance->id, 404);

        $schedule->delete();

        return response()->json(['message' => 'Programación eliminada.']);
    }
}
