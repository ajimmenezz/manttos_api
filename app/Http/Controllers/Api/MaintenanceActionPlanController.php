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
     * Genera una agenda día-por-día a nivel TAREA (dispositivo × actividad), ordenada
     * por las reglas de prioridad del mantenimiento (campo del directorio / tipo de
     * dispositivo / tipo de actividad, con orden explícito de valores). Las tareas se
     * ordenan y luego se llenan los días respetando el tope diario (ingenieros × min/día),
     * conservando el orden pedido (no first-fit). `device_schedules` guarda una fecha por
     * dispositivo, así que a cada dispositivo se le asigna su PRIMER día en la cola.
     */
    public function buildAgenda(Maintenance $maintenance): array
    {
        $plan      = $this->computePlan($maintenance);
        $tasks     = $plan['pending_tasks'];
        $engineers = $plan['engineers'];
        $dailyMin  = $plan['capacity']['daily_minutes_per_engineer'];
        $dailyCap  = $engineers * $dailyMin;

        $buckets = $this->normalizeBuckets($maintenance->agenda_rules['buckets'] ?? []);

        $start = $maintenance->start_date ? Carbon::parse($maintenance->start_date) : null;
        $end   = $maintenance->end_date   ? Carbon::parse($maintenance->end_date)   : null;
        $today = WorkCalendar::today();
        $planFrom = ($start && $today->lt($start)) ? $start : $today;

        $empty = fn (string $reason) => [
            'days' => [], 'assignments' => [], 'warnings' => [],
            'meta' => ['reason' => $reason, 'daily_capacity_minutes' => $dailyCap, 'engineers' => $engineers,
                       'working_days' => 0, 'total_devices' => 0, 'total_tasks' => 0,
                       'over_capacity' => false, 'ordered' => ! empty($buckets), 'rules_count' => count($buckets)],
        ];

        if (empty($tasks))                                    return $empty('sin_pendientes');
        if ($engineers === 0)                                 return $empty('sin_ingenieros');
        if (! $start || ! $end)                               return $empty('sin_fechas');
        if ($today->gt($end))                                 return $empty('vencido');

        $workingDates = WorkCalendar::workingDates($planFrom, $end);  // Carbon[]
        if (empty($workingDates))                             return $empty('sin_dias_habiles');

        // Ordena las tareas por los bloques de prioridad y les calcula la "ruta"
        // (el nombre del bloque que las colocó, ej. "Torre 1 · Preventivos").
        $ordered = $this->sortTasksByBuckets($tasks, $buckets);
        foreach ($ordered as &$t) $t['path'] = $this->bucketPath($t, $buckets);
        unset($t);

        // Llenado secuencial preservando el orden: si la tarea no cabe en el día actual
        // (y ya hay algo), avanza al siguiente; el último día absorbe el sobrecupo.
        $n = count($workingDates);
        $dayTasks = array_fill(0, $n, []);
        $dayLoad  = array_fill(0, $n, 0);
        $assignments = [];
        $overflow = false;
        $di = 0;

        foreach ($ordered as $t) {
            if ($di < $n - 1 && $dayLoad[$di] > 0 && $dayLoad[$di] + $t['minutes'] > $dailyCap) {
                $di++;
            }
            $dayTasks[$di][] = $t;
            $dayLoad[$di]   += $t['minutes'];
            if ($dayLoad[$di] > $dailyCap) $overflow = true;
            // Como $di es no decreciente, la primera aparición del dispositivo es su día más temprano.
            if (! isset($assignments[$t['device_id']])) {
                $assignments[$t['device_id']] = $workingDates[$di]->toDateString();
            }
        }

        $days = [];
        for ($i = 0; $i < $n; $i++) {
            if (empty($dayTasks[$i])) continue;
            $devIds = [];
            foreach ($dayTasks[$i] as $t) $devIds[$t['device_id']] = true;
            $days[] = [
                'date'             => $workingDates[$i]->toDateString(),
                'tasks'            => array_map(fn ($t) => [
                    'device_id'           => $t['device_id'],
                    'device_name'         => $t['device_name'],
                    'device_type_label'   => $t['device_type_label'],
                    'activity_type_id'    => $t['activity_type_id'],
                    'activity_type_label' => $t['activity_type_label'],
                    'minutes'             => $t['minutes'],
                    'path'                => $t['path'],
                ], $dayTasks[$i]),
                'task_count'       => count($dayTasks[$i]),
                'device_count'     => count($devIds),
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
                'total_tasks'  => count($ordered),
                'over_capacity' => $overflow,
                'ordered'      => ! empty($buckets),
                'rules_count'  => count($buckets),
            ],
        ];
    }

    // ── Bloques de prioridad de la agenda ─────────────────────────────────────
    // Modelo tipo "reglas de formulario": una lista ORDENADA de bloques; cada bloque
    // es un árbol de condiciones (Y/O anidable). Cada tarea cae en el PRIMER bloque
    // que cumple → ese índice manda el orden. Las que no caen en ninguno van al final.

    /** Operadores de condición soportados. `in`/`not_in` operan sobre un conjunto de valores. */
    private const AG_OPS = ['in', 'not_in', 'eq', 'neq', 'empty', 'not_empty'];
    private const AG_VALUELESS_OPS = ['empty', 'not_empty'];

    /** Claves sintéticas para condicionar por tipo de dispositivo / actividad (no son del directorio). */
    private const AG_DEVICE_TYPE   = '__device_type__';
    private const AG_ACTIVITY_TYPE = '__activity_type__';

    /** Arriba de este nº de valores, el campo se marca "alta cardinalidad" (el front usa buscador). */
    private const HIGH_CARD_THRESHOLD = 25;

    /** Tope de valores enviados por campo (para el buscador), evita payloads patológicos. */
    private const MAX_VALUES_SENT = 2000;

    /** Deja solo bloques bien formados (con al menos una condición válida). */
    private function normalizeBuckets($buckets): array
    {
        if (! is_array($buckets)) return [];
        $out = [];
        foreach ($buckets as $b) {
            if (! is_array($b)) continue;
            $group = $this->normalizeGroup($b['group'] ?? null);
            if ($group === null) continue;                    // bloque sin condiciones → se descarta
            $name = isset($b['name']) && is_string($b['name']) ? trim($b['name']) : '';
            $out[] = ['name' => $name, 'group' => $group];
        }
        return $out;
    }

    /** Normaliza un grupo recursivo; devuelve null si queda sin hijos válidos. */
    private function normalizeGroup($node): ?array
    {
        if (! is_array($node)) return null;
        $combinator = ($node['combinator'] ?? 'and') === 'or' ? 'or' : 'and';
        $childrenIn = is_array($node['children'] ?? null) ? $node['children'] : [];
        $children = [];
        foreach ($childrenIn as $c) {
            if (! is_array($c)) continue;
            if (($c['kind'] ?? null) === 'group') {
                $g = $this->normalizeGroup($c);
                if ($g !== null) $children[] = $g;
            } else {
                $cond = $this->normalizeCondition($c);
                if ($cond !== null) $children[] = $cond;
            }
        }
        if (empty($children)) return null;                    // grupo vacío no coincide con nada → se descarta
        return ['kind' => 'group', 'combinator' => $combinator, 'children' => $children];
    }

    /** Normaliza una condición; devuelve null si es inválida (sin campo, o sin valores cuando el operador los exige). */
    private function normalizeCondition($c): ?array
    {
        $field = $c['field'] ?? null;
        if (! is_string($field) || $field === '') return null;
        $op = $c['op'] ?? 'in';
        if (! in_array($op, self::AG_OPS, true)) $op = 'in';
        $values = is_array($c['values'] ?? null)
            ? array_values(array_filter(array_map(fn ($v) => (string) $v, $c['values']), fn ($v) => $v !== ''))
            : [];
        if (! in_array($op, self::AG_VALUELESS_OPS, true) && empty($values)) return null;
        return ['kind' => 'condition', 'field' => $field, 'op' => $op, 'values' => $values];
    }

    /** Valor comparable (string) de la tarea para un campo de condición. */
    private function taskFieldValue(array $task, string $field): string
    {
        if ($field === self::AG_DEVICE_TYPE)   return (string) $task['device_type_id'];
        if ($field === self::AG_ACTIVITY_TYPE) return (string) $task['activity_type_id'];
        $v = $task['custom_fields'][$field] ?? null;
        return is_array($v) ? '' : (string) ($v ?? '');
    }

    /** Evalúa un nodo (grupo o condición) contra una tarea. */
    private function evalAgNode(array $node, array $task): bool
    {
        if (($node['kind'] ?? '') === 'condition') return $this->evalAgCondition($node, $task);
        $children = $node['children'] ?? [];
        if (empty($children)) return false;
        if (($node['combinator'] ?? 'and') === 'and') {
            foreach ($children as $c) if (! $this->evalAgNode($c, $task)) return false;
            return true;
        }
        foreach ($children as $c) if ($this->evalAgNode($c, $task)) return true;
        return false;
    }

    private function evalAgCondition(array $cond, array $task): bool
    {
        $val = $this->taskFieldValue($task, $cond['field']);
        $set = $cond['values'] ?? [];
        return match ($cond['op']) {
            'in'        => in_array($val, $set, true),
            'not_in'    => ! in_array($val, $set, true),
            'eq'        => $val === ($set[0] ?? ''),
            'neq'       => $val !== ($set[0] ?? ''),
            'empty'     => $val === '',
            'not_empty' => $val !== '',
            default     => false,
        };
    }

    /** Índice del primer bloque que cumple la tarea (o count($buckets) si ninguno). */
    private function bucketIndex(array $task, array $buckets): int
    {
        foreach ($buckets as $i => $b) {
            if ($this->evalAgNode($b['group'], $task)) return $i;
        }
        return count($buckets);
    }

    /** Ordena las tareas por bloque (el primero que cumple manda), con orden natural de respaldo. */
    private function sortTasksByBuckets(array $tasks, array $buckets): array
    {
        foreach ($tasks as &$t) $t['__bucket'] = $this->bucketIndex($t, $buckets);
        unset($t);

        usort($tasks, function ($a, $b) {
            if ($a['__bucket'] !== $b['__bucket']) return $a['__bucket'] <=> $b['__bucket'];
            // Desempate estable dentro del bloque: dispositivo, luego actividad (mantiene juntas las tareas del mismo disp.).
            return [$a['device_id'], $a['activity_type_id']] <=> [$b['device_id'], $b['activity_type_id']];
        });

        return $tasks;
    }

    /** Ruta legible de la tarea = nombre del bloque que la colocó ("Bloque N" si no tiene nombre). */
    private function bucketPath(array $task, array $buckets): string
    {
        if (empty($buckets)) return '';
        $i = $task['__bucket'] ?? $this->bucketIndex($task, $buckets);
        if ($i >= count($buckets)) return 'Otros';
        $name = $buckets[$i]['name'] ?? '';
        return $name !== '' ? $name : ('Bloque ' . ($i + 1));
    }

    /**
     * GET /maintenances/{maintenance}/action-plan/agenda-options
     * Campos condicionables (directorio + tipo de dispositivo/actividad) con sus valores
     * presentes, más los bloques de prioridad guardados.
     */
    public function agendaOptions(Maintenance $maintenance): JsonResponse
    {
        $this->authorize($maintenance);

        $systemId = $maintenance->catalog_id;
        $siteId   = $maintenance->site_id;

        $devices = Device::whereHas('directory', fn ($q) =>
                $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
            )->where('is_active', true)->get(['id', 'device_type', 'custom_fields']);

        $fields = [];

        // Campos del directorio condicionables (excluye tipos no discretos).
        $skip = ['image', 'signature', 'did'];
        $sysFields = \App\Models\SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->whereNotIn('field_type', $skip)
            ->orderBy('sort_order')->get(['label', 'field_key', 'field_type']);

        foreach ($sysFields as $f) {
            $vals = $devices
                ->map(fn ($d) => is_array($d->custom_fields) ? ($d->custom_fields[$f->field_key] ?? null) : null)
                ->filter(fn ($v) => $v !== null && $v !== '' && ! is_array($v))
                ->map(fn ($v) => (string) $v)
                ->unique()->values()->all();
            usort($vals, 'strnatcasecmp');
            $count = count($vals);
            if ($count < 1) continue;                          // sin valores → no aporta al orden
            $truncated = $count > self::MAX_VALUES_SENT;
            if ($truncated) $vals = array_slice($vals, 0, self::MAX_VALUES_SENT);
            $fields[] = [
                'key'              => $f->field_key,
                'label'            => $f->label,
                'kind'             => 'directory',
                'field_type'       => $f->field_type,
                'values'           => array_map(fn ($v) => ['id' => $v, 'label' => $v], $vals),
                'value_count'      => $count,
                'high_cardinality' => $count > self::HIGH_CARD_THRESHOLD,
                'values_truncated' => $truncated,
            ];
        }

        // Tipo de dispositivo (presentes en el sitio).
        $system    = Catalog::findOrFail($systemId);
        $typeById  = $system->deviceTypes()->orderBy('catalogs.label')->pluck('catalogs.label', 'catalogs.id');
        $presentLabels = $devices->pluck('device_type')->unique();
        $deviceTypeVals = [];
        foreach ($typeById as $id => $label) {
            if ($presentLabels->contains($label)) $deviceTypeVals[] = ['id' => (string) $id, 'label' => $label];
        }
        if (! empty($deviceTypeVals)) {
            $fields[] = [
                'key' => self::AG_DEVICE_TYPE, 'label' => 'Tipo de dispositivo', 'kind' => 'device_type',
                'field_type' => 'list', 'values' => $deviceTypeVals,
                'value_count' => count($deviceTypeVals), 'high_cardinality' => false, 'values_truncated' => false,
            ];
        }

        // Tipo de actividad (enlazados al sistema).
        $linkedTypeIds = DB::table('activity_type_systems')->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypeVals = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)->where('is_active', true)
            ->orderBy('label')->get(['id', 'label'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'label' => $c->label])->values()->all();
        if (! empty($activityTypeVals)) {
            $fields[] = [
                'key' => self::AG_ACTIVITY_TYPE, 'label' => 'Tipo de actividad', 'kind' => 'activity_type',
                'field_type' => 'list', 'values' => $activityTypeVals,
                'value_count' => count($activityTypeVals), 'high_cardinality' => false, 'values_truncated' => false,
            ];
        }

        return response()->json([
            'fields'  => $fields,
            'buckets' => $this->normalizeBuckets($maintenance->agenda_rules['buckets'] ?? []),
        ]);
    }

    /** PUT /maintenances/{maintenance}/action-plan/rules — guarda los bloques de prioridad. */
    public function saveRules(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorize($maintenance);

        $request->validate([
            'buckets'         => 'present|array',
            'buckets.*.name'  => 'nullable|string|max:80',
            'buckets.*.group' => 'required|array',
        ]);

        $buckets = $this->normalizeBuckets($request->input('buckets', []));
        $maintenance->update(['agenda_rules' => empty($buckets) ? null : ['buckets' => $buckets]]);

        return response()->json(['message' => 'Bloques de prioridad guardados.', 'buckets' => $buckets]);
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
            )->where('is_active', true)->get(['id', 'name', 'device_type', 'custom_fields']);
        $deviceMap = $devices->keyBy('id');

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
        $tasks = [];         // tareas pendientes (dispositivo × actividad) para la agenda
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
                    $occ  = max(0, $K - $d);
                    $pend = $occ * $min;
                    if ($pend > 0) {
                        $devicePending[$did] = ($devicePending[$did] ?? 0) + $pend;
                        $dev = $deviceMap[$did] ?? null;
                        $tasks[] = [
                            'device_id'           => (int) $did,
                            'device_name'         => $dev->name ?? ('#' . $did),
                            'device_type_id'      => (int) $dt->id,
                            'device_type_label'   => $typeLabel[$dt->id],
                            'activity_type_id'    => (int) $at->id,
                            'activity_type_label' => $activityLabel[$at->id],
                            'minutes'             => $pend,
                            'occurrences'         => $occ,
                            'custom_fields'       => is_array($dev->custom_fields ?? null) ? $dev->custom_fields : [],
                        ];
                    }
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
            // Uso interno para la agenda:
            'devices_pending' => $devicePending,
            'pending_tasks'   => $tasks,
        ];
    }
}
