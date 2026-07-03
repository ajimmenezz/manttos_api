<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Device;
use App\Models\DeviceSchedule;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\MaintenanceContractFrequency;
use App\Models\MaintenanceFrequency;
use App\Models\TaskDuration;
use App\Support\WorkCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plan de acción por mantenimiento: carga (tareas × minutos) vs. capacidad
 * (ingenieros × días hábiles × horas) en el periodo. Tablero vivo (descuenta lo
 * realizado). Sección restringida al permiso `maintenances.action-plan`.
 */
class MaintenanceActionPlanController extends Controller
{
    private function authorize(Maintenance $maintenance): void
    {
        $user = request()->user();
        abort_unless($user->can('maintenances.action-plan'), 403, 'Sin acceso al plan de acción.');
        // Sólo superadmin/admin tienen el permiso; igual validamos acceso al recurso.
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    /** GET /maintenances/{maintenance}/action-plan */
    public function show(Maintenance $maintenance): JsonResponse
    {
        $this->authorize($maintenance);
        return response()->json($this->computePlan($maintenance));
    }

    /** GET /maintenances/{maintenance}/action-plan/agenda — propuesta sin persistir. */
    public function agenda(Maintenance $maintenance): JsonResponse
    {
        $this->authorize($maintenance);
        return response()->json($this->buildAgenda($maintenance));
    }

    /** POST /maintenances/{maintenance}/action-plan/agenda — aplica la agenda a device_schedules. */
    public function applyAgenda(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorize($maintenance);

        $agenda = $this->buildAgenda($maintenance);
        $assignments = $agenda['assignments'];
        if (empty($assignments)) {
            return response()->json(['message' => 'No hay dispositivos pendientes por programar.', 'scheduled' => 0]);
        }

        $now = now();
        $userId = $request->user()->id;
        $records = [];
        foreach ($assignments as $deviceId => $date) {
            $records[] = [
                'maintenance_id' => $maintenance->id,
                'device_id'      => (int) $deviceId,
                'scheduled_date' => $date,
                'created_by'     => $userId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        // Upsert: reprograma si ya existía la combinación mantenimiento+dispositivo.
        DeviceSchedule::upsert($records, ['maintenance_id', 'device_id'], ['scheduled_date', 'updated_at']);

        return response()->json(['message' => count($records) . ' dispositivo(s) programados.', 'scheduled' => count($records)]);
    }

    /**
     * Genera una agenda día-por-día repartiendo los dispositivos pendientes en los
     * días hábiles restantes, respetando el tope diario (ingenieros × minutos/día).
     * Como device_schedules es una fecha por dispositivo, la unidad es el dispositivo
     * (se suman los minutos de todas sus actividades pendientes). First-fit decreasing.
     */
    public function buildAgenda(Maintenance $maintenance): array
    {
        $plan      = $this->computePlan($maintenance);
        $pending   = $plan['devices_pending'];                       // device_id => minutos
        $engineers = $plan['engineers'];
        $dailyMin  = $plan['capacity']['daily_minutes_per_engineer'];
        $dailyCap  = $engineers * $dailyMin;

        $start = $maintenance->start_date ? Carbon::parse($maintenance->start_date) : null;
        $end   = $maintenance->end_date   ? Carbon::parse($maintenance->end_date)   : null;
        $today = WorkCalendar::today();
        $planFrom = ($start && $today->lt($start)) ? $start : $today;

        $empty = fn (string $reason) => [
            'days' => [], 'assignments' => [], 'warnings' => [],
            'meta' => ['reason' => $reason, 'daily_capacity_minutes' => $dailyCap, 'engineers' => $engineers,
                       'working_days' => 0, 'total_devices' => 0, 'over_capacity' => false],
        ];

        if (empty($pending))                                  return $empty('sin_pendientes');
        if ($engineers === 0)                                 return $empty('sin_ingenieros');
        if (! $start || ! $end)                               return $empty('sin_fechas');
        if ($today->gt($end))                                 return $empty('vencido');

        $workingDates = WorkCalendar::workingDates($planFrom, $end);  // Carbon[]
        if (empty($workingDates))                             return $empty('sin_dias_habiles');

        $names = Device::whereIn('id', array_keys($pending))->pluck('name', 'id');

        // First-fit decreasing (los más pesados primero).
        $items = [];
        foreach ($pending as $id => $min) $items[] = ['id' => (int) $id, 'min' => (int) $min];
        usort($items, fn ($a, $b) => $b['min'] <=> $a['min']);

        $n = count($workingDates);
        $dayLoad = array_fill(0, $n, 0);
        $dayDevices = array_fill(0, $n, []);
        $assignments = [];
        $overflow = false;

        foreach ($items as $it) {
            $placed = -1;
            for ($i = 0; $i < $n; $i++) {
                if ($dayLoad[$i] + $it['min'] <= $dailyCap) { $placed = $i; break; }
            }
            if ($placed === -1) {
                // No cabe en ningún día → al día menos cargado (sobrecupo).
                $placed = 0;
                for ($i = 1; $i < $n; $i++) if ($dayLoad[$i] < $dayLoad[$placed]) $placed = $i;
                $overflow = true;
            }
            $dayLoad[$placed] += $it['min'];
            $dayDevices[$placed][] = ['id' => $it['id'], 'name' => $names[$it['id']] ?? ('#' . $it['id']), 'minutes' => $it['min']];
            $assignments[$it['id']] = $workingDates[$placed]->toDateString();
        }

        $days = [];
        for ($i = 0; $i < $n; $i++) {
            if (empty($dayDevices[$i])) continue;
            $days[] = [
                'date'             => $workingDates[$i]->toDateString(),
                'devices'          => $dayDevices[$i],
                'device_count'     => count($dayDevices[$i]),
                'total_minutes'    => $dayLoad[$i],
                'capacity_minutes' => $dailyCap,
                'over_capacity'    => $dayLoad[$i] > $dailyCap,
            ];
        }

        $warnings = [];
        if ($overflow) $warnings[] = 'La carga no cabe en el periodo con los ingenieros actuales; algunos días exceden la capacidad.';

        return [
            'days' => $days,
            'assignments' => $assignments,
            'warnings' => $warnings,
            'meta' => [
                'daily_capacity_minutes' => $dailyCap,
                'engineers'    => $engineers,
                'working_days' => $n,
                'total_devices' => count($assignments),
                'over_capacity' => $overflow,
            ],
        ];
    }

    /**
     * Núcleo del cálculo. Devuelve indicadores + desgloses + pendiente por dispositivo
     * (este último lo aprovecha la generación de agenda).
     */
    public function computePlan(Maintenance $maintenance): array
    {
        $systemId = $maintenance->catalog_id;
        $siteId   = $maintenance->site_id;
        $isContract = $maintenance->type === 'contrato';

        $warnings = [];

        // ── Dispositivos del sistema en el sitio, por tipo ────────────────────
        $devices = Device::whereHas('directory', fn ($q) =>
                $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
            )->where('is_active', true)->get(['id', 'device_type']);

        $system      = Catalog::findOrFail($systemId);
        $deviceTypes = $system->deviceTypes()->orderBy('catalogs.label')->get(['catalogs.id', 'catalogs.label']);
        $labelToId   = $deviceTypes->pluck('id', 'label');
        $typeLabel   = $deviceTypes->pluck('label', 'id');

        $deviceIdsByType = [];   // device_type_id => [device_id,...]
        foreach ($devices as $d) {
            $tid = $labelToId[$d->device_type] ?? null;
            if ($tid) $deviceIdsByType[$tid][] = $d->id;
        }
        $allDeviceIds = $devices->pluck('id');

        // Tipos de actividad enlazados al sistema
        $linkedTypeIds = DB::table('activity_type_systems')->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)->where('is_active', true)
            ->orderBy('label')->get(['id', 'label']);
        $activityLabel = $activityTypes->pluck('label', 'id');

        // ── Tiempos por tarea (minutos) ───────────────────────────────────────
        $minutes = []; // "dt:at" => minutos
        foreach (TaskDuration::where('system_id', $systemId)->get() as $t) {
            $minutes["{$t->device_type_id}:{$t->activity_type_id}"] = (int) $t->minutes;
        }

        // ── Frecuencias efectivas (solo contrato): catálogo + override ────────
        $freq = [];
        if ($isContract) {
            foreach (MaintenanceFrequency::where('system_id', $systemId)->get() as $b) {
                $freq["{$b->device_type_id}:{$b->activity_type_id}"] = ['value' => $b->period_value, 'unit' => $b->period_unit];
            }
            foreach (MaintenanceContractFrequency::where('maintenance_id', $maintenance->id)->get() as $o) {
                $freq["{$o->device_type_id}:{$o->activity_type_id}"] = ['value' => $o->period_value, 'unit' => $o->period_unit];
            }
        }

        $periodDays = ($maintenance->start_date && $maintenance->end_date)
            ? Carbon::parse($maintenance->start_date)->diffInDays(Carbon::parse($maintenance->end_date)) + 1
            : null;
        $freqToDays = fn ($value, $unit) => match ($unit) {
            'days'   => (int) $value, 'months' => (int) $value * 30, 'years' => (int) $value * 365, default => 0,
        };

        // Ocurrencias K por celda
        $Kfor = function (int $dt, int $at) use ($isContract, $freq, $freqToDays, $periodDays): int {
            if (! $isContract) return 1;
            $f = $freq["{$dt}:{$at}"] ?? null;
            if (! $f || $f['unit'] === 'as_needed' || ! $periodDays) return 0;
            $fd = $freqToDays($f['value'], $f['unit']);
            return $fd > 0 ? intdiv($periodDays, $fd) : 0;
        };

        // ── Conteo de actividades realizadas por dispositivo y tipo ───────────
        $activities = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->whereIn('device_id', $allDeviceIds)
            ->get(['device_id', 'activity_type_id']);
        $doneByDeviceType = []; // "device_id:at" => count
        foreach ($activities as $a) {
            $k = "{$a->device_id}:{$a->activity_type_id}";
            $doneByDeviceType[$k] = ($doneByDeviceType[$k] ?? 0) + 1;
        }

        // ── Recorre celdas con tiempo configurado ────────────────────────────
        $byActivity = [];   // at => [...]
        $byType     = [];   // dt => [...]
        $devicePending = []; // device_id => minutos pendientes
        $totalRequired = 0; $totalDone = 0; $totalRemaining = 0;
        $totalMinutes = 0;  $remainingMinutes = 0;
        $missingDurations = [];

        foreach ($activityTypes as $at) {
            foreach ($deviceTypes as $dt) {
                $cellKey = "{$dt->id}:{$at->id}";
                $ids = $deviceIdsByType[$dt->id] ?? [];
                $D   = count($ids);
                if ($D === 0) continue;

                $K = $Kfor($dt->id, $at->id);

                // Solo en CONTRATO: si el contrato exige la tarea (tiene frecuencia) pero
                // no hay tiempo configurado, no se puede estimar → se avisa. En NORMAL la
                // presencia del tiempo define la aplicabilidad, así que una celda sin tiempo
                // simplemente no es una tarea (se omite en silencio).
                if ($isContract && $K > 0 && ! isset($minutes[$cellKey])) {
                    $missingDurations[] = ['device_type' => $typeLabel[$dt->id], 'activity_type' => $activityLabel[$at->id]];
                    continue;
                }
                if (! isset($minutes[$cellKey]) || $K <= 0) continue;

                $min = $minutes[$cellKey];
                $required = $D * $K;
                $done = 0;
                foreach ($ids as $did) {
                    $d = min($doneByDeviceType["{$did}:{$at->id}"] ?? 0, $K);
                    $done += $d;
                    $pend = max(0, $K - $d) * $min;
                    if ($pend > 0) $devicePending[$did] = ($devicePending[$did] ?? 0) + $pend;
                }
                $remaining = max(0, $required - $done);

                $byActivity[$at->id] ??= ['id' => $at->id, 'label' => $at->label, 'required' => 0, 'done' => 0, 'remaining' => 0, 'remaining_minutes' => 0];
                $byActivity[$at->id]['required'] += $required;
                $byActivity[$at->id]['done'] += $done;
                $byActivity[$at->id]['remaining'] += $remaining;
                $byActivity[$at->id]['remaining_minutes'] += $remaining * $min;

                $byType[$dt->id] ??= ['id' => $dt->id, 'label' => $typeLabel[$dt->id], 'required' => 0, 'done' => 0, 'remaining' => 0, 'remaining_minutes' => 0];
                $byType[$dt->id]['required'] += $required;
                $byType[$dt->id]['done'] += $done;
                $byType[$dt->id]['remaining'] += $remaining;
                $byType[$dt->id]['remaining_minutes'] += $remaining * $min;

                $totalRequired += $required; $totalDone += $done; $totalRemaining += $remaining;
                $totalMinutes += $required * $min; $remainingMinutes += $remaining * $min;
            }
        }

        // ── Calendario / capacidad ────────────────────────────────────────────
        $engineers = $maintenance->engineers()->count();
        $dailyMin  = WorkCalendar::dailyMinutesPerEngineer();
        $cfg       = WorkCalendar::config();
        $today     = WorkCalendar::today();

        $start = $maintenance->start_date ? Carbon::parse($maintenance->start_date) : null;
        $end   = $maintenance->end_date   ? Carbon::parse($maintenance->end_date)   : null;

        $workingDaysTotal = ($start && $end) ? WorkCalendar::workingDaysBetween($start, $end) : null;
        $isOverdue = $end ? $today->gt($end) : false;
        $planFrom = $start && $today->lt($start) ? $start : $today;
        $workingDaysRemaining = ($start && $end && ! $isOverdue)
            ? WorkCalendar::workingDaysBetween($planFrom, $end)
            : 0;

        // ── Indicadores ───────────────────────────────────────────────────────
        $daysNeeded = ($engineers > 0 && $dailyMin > 0)
            ? (int) ceil($remainingMinutes / ($engineers * $dailyMin)) : null;
        $engineersNeeded = ($workingDaysRemaining > 0 && $dailyMin > 0 && $remainingMinutes > 0)
            ? (int) ceil($remainingMinutes / ($workingDaysRemaining * $dailyMin)) : null;
        $fits = ($daysNeeded !== null && $workingDaysRemaining > 0) ? $daysNeeded <= $workingDaysRemaining : null;
        $dailyTargetTasks = $workingDaysRemaining > 0 ? (int) ceil($totalRemaining / $workingDaysRemaining) : null;

        // ── Avisos ────────────────────────────────────────────────────────────
        if (! $start || ! $end) $warnings[] = 'El mantenimiento no tiene fechas de inicio/fin; no se puede calcular el periodo.';
        if ($engineers === 0)   $warnings[] = 'No hay ingenieros asignados al mantenimiento.';
        if (empty($minutes))    $warnings[] = 'Este sistema no tiene tiempos por tarea configurados (catálogo → Sistemas → Tiempos).';
        if (! empty($missingDurations)) $warnings[] = 'Hay tareas del contrato sin tiempo configurado; no se contaron en la carga.';
        if ($isOverdue)         $warnings[] = 'El periodo del mantenimiento ya venció.';

        $canPlan = $totalRequired > 0 && $start && $end && ! $isOverdue;

        return [
            'can_plan' => $canPlan,
            'is_contract' => $isContract,
            'warnings' => $warnings,
            'period' => [
                'start' => $maintenance->start_date,
                'end'   => $maintenance->end_date,
                'today' => $today->toDateString(),
                'working_days_total' => $workingDaysTotal,
                'working_days_remaining' => $workingDaysRemaining,
                'is_overdue' => $isOverdue,
            ],
            'calendar' => [
                'work_days' => $cfg['work_days'],
                'hours_per_day' => $cfg['hours_per_day'],
            ],
            'engineers' => $engineers,
            'workload' => [
                'total_tasks' => $totalRequired,
                'done_tasks' => $totalDone,
                'remaining_tasks' => $totalRemaining,
                'total_minutes' => $totalMinutes,
                'remaining_minutes' => $remainingMinutes,
                'progress_pct' => $totalRequired > 0 ? round($totalDone / $totalRequired * 100, 1) : 0,
            ],
            'capacity' => [
                'daily_minutes_per_engineer' => $dailyMin,
                'remaining_capacity_minutes' => $engineers * $workingDaysRemaining * $dailyMin,
                'days_needed' => $daysNeeded,
                'engineers_needed' => $engineersNeeded,
                'fits' => $fits,
                'daily_target_tasks' => $dailyTargetTasks,
            ],
            'by_activity' => array_values($byActivity),
            'by_device_type' => array_values($byType),
            'missing_durations' => $missingDurations,
            // Uso interno para la agenda (Fase 4):
            'devices_pending' => $devicePending,
        ];
    }
}
