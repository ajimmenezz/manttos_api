<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceSchedule;
use App\Models\Maintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceScheduleController extends Controller
{
    public function index(Maintenance $maintenance): JsonResponse
    {
        $schedules = DeviceSchedule::where('maintenance_id', $maintenance->id)
            ->get(['id', 'device_id', 'scheduled_date']);

        return response()->json($schedules);
    }

    public function store(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para programar dispositivos.');

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
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para reprogramar.');
        abort_unless($schedule->maintenance_id === $maintenance->id, 404);

        $data = $request->validate([
            'scheduled_date' => 'required|date|after:today',
        ]);

        $schedule->update($data);

        return response()->json(['message' => 'Programación actualizada.', 'schedule' => $schedule]);
    }

    public function destroy(Request $request, Maintenance $maintenance, DeviceSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para desprogramar.');
        abort_unless($schedule->maintenance_id === $maintenance->id, 404);

        $schedule->delete();

        return response()->json(['message' => 'Programación eliminada.']);
    }
}
