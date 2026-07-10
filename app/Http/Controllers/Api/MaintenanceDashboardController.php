<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\MaintenanceActivityFilters;
use App\Models\ActivityTypeField;
use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\MaintenanceContractFrequency;
use App\Models\MaintenanceFrequency;
use App\Models\SystemField;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MaintenanceDashboardController extends Controller
{
    use MaintenanceActivityFilters;

    private function authorizeAccess(Maintenance $maintenance): void
    {
        $user = request()->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        if ($user->hasRole('admin-sitio') &&
            $user->sitesAsAdmin()->where('sites.id', $maintenance->site_id)->exists()) return;
        if ($user->hasRole('admin-cliente')) {
            $maintenance->loadMissing('site');
            if ($user->clientsAsAdmin()->where('clients.id', $maintenance->site->client_id)->exists()) return;
        }
        if ($user->hasRole('ingeniero') &&
            $maintenance->engineers()->where('users.id', $user->id)->exists()) return;
        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    public function show(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $request  = request();
        $maintId  = $maintenance->id;
        $systemId = $maintenance->catalog_id;
        $siteId   = $maintenance->site_id;

        // ── Filtros ───────────────────────────────────────────────────────────
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')
            ? Carbon::parse($request->date_to)->endOfDay()     : null;

        $filters = $this->parseMaintenanceFilters($request);

        // ── Universo base (sin filtros de campo): dispositivos y actividades ──
        $baseDeviceQuery = Device::whereHas('directory', fn ($q) =>
            $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
        )->where('is_active', true);

        $baseDevices = (clone $baseDeviceQuery)->get(['id', 'custom_fields']);

        $baseActivities = MaintenanceActivity::where('maintenance_id', $maintId)
            ->select('id', 'device_id', 'activity_type_id', 'user_id', 'field_values', 'performed_at')
            ->whereIn('device_id', $baseDevices->pluck('id'))
            ->get();

        // Tipos de actividad activos y vinculados al sistema
        $linkedTypeIds = DB::table('activity_type_systems')
            ->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)
            ->where('is_active', true)
            ->get(['id', 'label']);

        // Metadatos de filtros disponibles (directorio + formulario) sobre el universo base
        $filterMeta = $this->maintenanceFilterMeta($systemId, $baseDevices, $baseActivities, $activityTypes);

        // ── Dispositivos en scope (aplicar filtros de directorio) ─────────────
        $this->applyDirectoryFilters($baseDeviceQuery, $filters['dir'], $filterMeta['dir_modes']);
        $deviceIds    = $baseDeviceQuery->pluck('id');
        $deviceIdSet  = array_flip($deviceIds->all());
        $totalDevices = $deviceIds->count();

        // ── Actividades en scope: dispositivo + fecha + filtros de formulario ─
        $activities = $baseActivities
            ->filter(fn ($a) => isset($deviceIdSet[$a->device_id]))
            ->filter(fn ($a) => (!$dateFrom || Carbon::parse($a->performed_at)->gte($dateFrom))
                             && (!$dateTo   || Carbon::parse($a->performed_at)->lte($dateTo)))
            ->filter(fn ($a) => $this->activityPassesFormFilters($a, $filters['form'], $filterMeta['form_modes']))
            ->values();

        $totalActivities   = $activities->count();
        $coveredDeviceIds  = $activities->pluck('device_id')->unique();
        $coveredCount      = $coveredDeviceIds->count();

        // ── Cobertura por tipo de actividad ───────────────────────────────────
        $coverageByType = $activityTypes->map(function ($type) use ($activities, $totalDevices) {
            $covered = $activities->where('activity_type_id', $type->id)->pluck('device_id')->unique()->count();
            return [
                'id'           => $type->id,
                'label'        => $type->label,
                'covered'      => $covered,
                'total'        => $totalDevices,
                'pct'          => $totalDevices > 0 ? round($covered / $totalDevices * 100, 1) : 0,
                'activity_count' => $activities->where('activity_type_id', $type->id)->count(),
            ];
        })->values();

        // ── Por ingeniero ─────────────────────────────────────────────────────
        $byEngineer = $activities->groupBy('user_id')->map(function ($acts, $userId) {
            $user = \App\Models\User::find($userId, ['id', 'name']);
            return [
                'user_id'         => $userId,
                'name'            => $user?->name ?? 'Desconocido',
                'activity_count'  => $acts->count(),
                'devices_covered' => $acts->pluck('device_id')->unique()->count(),
                'last_activity'   => $acts->max('performed_at'),
            ];
        })->sortByDesc('activity_count')->values();

        // ── Evolución semanal ─────────────────────────────────────────────────
        $start = Carbon::parse($maintenance->start_date)->startOfWeek();
        $end   = Carbon::parse($maintenance->end_date)->endOfWeek();

        $weeklyRaw = $activities
            ->groupBy(fn ($a) => Carbon::parse($a->performed_at)->startOfWeek()->toDateString());

        $weekly = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key   = $cursor->toDateString();
            $count = $weeklyRaw->get($key)?->count() ?? 0;
            $weekly[] = [
                'week_start' => $key,
                'label'      => 'Sem. ' . $cursor->weekOfYear . ' (' . $cursor->format('d M') . ')',
                'count'      => $count,
            ];
            $cursor->addWeek();
        }

        // ── Agrupaciones por campos del directorio marcados para dashboard ────
        // Cargar custom_fields de los dispositivos en scope (para los desgloses BI)
        $devices = $baseDevices->whereIn('id', $deviceIds->all())->values();

        $fieldBreakdowns = $this->buildFieldBreakdowns($systemId, $devices, $coveredDeviceIds);
        $formBreakdowns  = $this->buildFormBreakdowns($systemId, $activities, $activityTypes);

        // ── Días transcurridos ────────────────────────────────────────────────
        $startDate  = Carbon::parse($maintenance->start_date);
        $endDate    = Carbon::parse($maintenance->end_date);
        $today      = Carbon::today();
        $totalDays  = $startDate->diffInDays($endDate) + 1;
        $elapsed    = $today->between($startDate, $endDate)
            ? $startDate->diffInDays($today) + 1
            : ($today->gt($endDate) ? $totalDays : 0);

        return response()->json([
            'summary' => [
                'total_devices'     => $totalDevices,
                'covered_devices'   => $coveredCount,
                'coverage_pct'      => $totalDevices > 0 ? round($coveredCount / $totalDevices * 100, 1) : 0,
                'total_activities'  => $totalActivities,
                'active_engineers'  => $byEngineer->count(),
                'elapsed_days'      => $elapsed,
                'total_days'        => $totalDays,
            ],
            'coverage_by_type'   => $coverageByType,
            'by_engineer'        => $byEngineer,
            'weekly'             => $weekly,
            'field_breakdowns'   => $fieldBreakdowns,
            'available_filters'  => $filterMeta['available'],
            'form_breakdowns'    => $formBreakdowns,
        ]);
    }

    /**
     * Dashboard de cumplimiento para mantenimientos tipo contrato.
     * Requerido = nº dispositivos × K (veces que cabe la frecuencia en el periodo completo).
     * Realizadas se topan por dispositivo a K. El filtro de fechas solo afecta lo realizado.
     */
    public function contractDashboard(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $request  = request();
        $systemId = $maintenance->catalog_id;
        $siteId   = $maintenance->site_id;

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        // ── Dispositivos del sistema en el sitio, agrupados por tipo ──────────
        $devices   = Device::whereHas('directory', fn ($q) =>
                $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
            )->where('is_active', true)->get(['id', 'device_type']);
        $deviceIds = $devices->pluck('id');

        $system      = Catalog::findOrFail($systemId);
        $deviceTypes = $system->deviceTypes()->orderBy('catalogs.label')->get(['catalogs.id', 'catalogs.label']);
        $labelToId   = $deviceTypes->pluck('id', 'label'); // label => id

        $deviceIdsByType = [];          // device_type_id => [device_id,...]
        foreach ($devices as $d) {
            $tid = $labelToId[$d->device_type] ?? null;
            if ($tid) $deviceIdsByType[$tid][] = $d->id;
        }

        // Tipos de actividad enlazados al sistema
        $linkedTypeIds = DB::table('activity_type_systems')->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)->where('is_active', true)
            ->orderBy('label')->get(['id', 'label']);

        // ── Frecuencias efectivas: base del catálogo + override del contrato ──
        $freq = []; // "dt:at" => ['value'=>?, 'unit'=>]
        foreach (MaintenanceFrequency::where('system_id', $systemId)->get() as $b) {
            $freq["{$b->device_type_id}:{$b->activity_type_id}"] = ['value' => $b->period_value, 'unit' => $b->period_unit];
        }
        foreach (MaintenanceContractFrequency::where('maintenance_id', $maintenance->id)->get() as $o) {
            $freq["{$o->device_type_id}:{$o->activity_type_id}"] = ['value' => $o->period_value, 'unit' => $o->period_unit];
        }

        // Periodo completo del contrato (no se afecta por el filtro)
        $periodDays = Carbon::parse($maintenance->start_date)->diffInDays(Carbon::parse($maintenance->end_date)) + 1;
        $freqToDays = fn ($value, $unit) => match ($unit) {
            'days'   => (int) $value,
            'months' => (int) $value * 30,
            'years'  => (int) $value * 365,
            default  => 0, // as_needed
        };

        // ── Actividades (filtradas por fecha) ─────────────────────────────────
        $actQuery = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->whereIn('device_id', $deviceIds)
            ->select('id', 'device_id', 'activity_type_id', 'user_id', 'performed_at');
        if ($dateFrom) $actQuery->where('performed_at', '>=', $dateFrom);
        if ($dateTo)   $actQuery->where('performed_at', '<=', $dateTo);
        $activities = $actQuery->get();

        // ── Matriz + agregados ────────────────────────────────────────────────
        $matrix = [];
        $onDemand = [];
        $byActivity = []; // at_id => ['label','required','completed']
        $totalRequired = 0;
        $totalCompleted = 0;

        foreach ($activityTypes as $at) {
            $byActivity[$at->id] = ['id' => $at->id, 'label' => $at->label, 'required' => 0, 'completed' => 0];
            $actsOfType = $activities->where('activity_type_id', $at->id);
            $countsByDevice = $actsOfType->groupBy('device_id')->map->count();

            foreach ($deviceTypes as $dt) {
                $key = "{$dt->id}:{$at->id}";
                if (!isset($freq[$key])) continue;
                $f   = $freq[$key];
                $ids = $deviceIdsByType[$dt->id] ?? [];
                $D   = count($ids);

                if ($f['unit'] === 'as_needed') {
                    $done = 0;
                    foreach ($ids as $did) $done += $countsByDevice[$did] ?? 0;
                    if ($D > 0) $onDemand[] = [
                        'device_type' => $dt->label, 'activity_type' => $at->label,
                        'devices' => $D, 'done' => $done,
                    ];
                    continue;
                }

                $freqDays = $freqToDays($f['value'], $f['unit']);
                $K = $freqDays > 0 ? intdiv($periodDays, $freqDays) : 0;
                if ($K <= 0 || $D === 0) continue;

                $required  = $D * $K;
                $completed = 0;
                foreach ($ids as $did) $completed += min($countsByDevice[$did] ?? 0, $K);

                $matrix[] = [
                    'device_type'   => $dt->label,
                    'activity_type' => $at->label,
                    'devices'       => $D,
                    'per_device'    => $K,
                    'required'      => $required,
                    'completed'     => $completed,
                    'pct'           => round($completed / $required * 100, 1),
                ];

                $byActivity[$at->id]['required']  += $required;
                $byActivity[$at->id]['completed'] += $completed;
                $totalRequired  += $required;
                $totalCompleted += $completed;
            }
        }

        $byActivityOut = collect($byActivity)
            ->filter(fn ($r) => $r['required'] > 0)
            ->map(fn ($r) => [
                'id'        => $r['id'],
                'label'     => $r['label'],
                'required'  => $r['required'],
                'completed' => $r['completed'],
                'pending'   => max(0, $r['required'] - $r['completed']),
                'pct'       => $r['required'] > 0 ? round($r['completed'] / $r['required'] * 100, 1) : 0,
            ])->values();

        // ── Por ingeniero ─────────────────────────────────────────────────────
        $byEngineer = $activities->groupBy('user_id')->map(function ($acts, $userId) {
            $user = \App\Models\User::find($userId, ['id', 'name']);
            return [
                'user_id'         => $userId,
                'name'            => $user?->name ?? 'Desconocido',
                'activity_count'  => $acts->count(),
                'devices_covered' => $acts->pluck('device_id')->unique()->count(),
                'last_activity'   => $acts->max('performed_at'),
            ];
        })->sortByDesc('activity_count')->values();

        // ── Actividades por semana (orden cronológico, solo semanas ya vividas) ─
        $start       = Carbon::parse($maintenance->start_date)->startOfWeek();
        $contractEnd = Carbon::parse($maintenance->end_date)->endOfWeek();
        $todayEnd    = Carbon::today()->endOfWeek();
        // No mostrar semanas futuras: el corte es la semana actual (o el fin del contrato si ya terminó).
        $weekEnd     = $todayEnd->lt($contractEnd) ? $todayEnd : $contractEnd;
        $weeklyRaw = $activities->groupBy(fn ($a) => Carbon::parse($a->performed_at)->startOfWeek()->toDateString());
        $weekly = [];
        $cursor = $start->copy();
        while ($cursor->lte($weekEnd)) {
            $k = $cursor->toDateString();
            $weekly[] = [
                'week_start' => $k,
                'label'      => 'Sem. ' . $cursor->weekOfYear . ' (' . $cursor->format('d M') . ')',
                'count'      => $weeklyRaw->get($k)?->count() ?? 0,
            ];
            $cursor->addWeek();
        }

        // ── Días del periodo ──────────────────────────────────────────────────
        $today   = Carbon::today();
        $sd      = Carbon::parse($maintenance->start_date);
        $ed      = Carbon::parse($maintenance->end_date);
        $elapsed = $today->between($sd, $ed) ? $sd->diffInDays($today) + 1 : ($today->gt($ed) ? $periodDays : 0);

        return response()->json([
            'summary' => [
                'total_devices'   => $deviceIds->count(),
                'total_required'  => $totalRequired,
                'total_completed' => $totalCompleted,
                'pending'         => max(0, $totalRequired - $totalCompleted),
                'compliance_pct'  => $totalRequired > 0 ? round($totalCompleted / $totalRequired * 100, 1) : 0,
                'active_engineers' => $byEngineer->count(),
                'elapsed_days'    => $elapsed,
                'total_days'      => $periodDays,
            ],
            'by_activity' => $byActivityOut,
            'matrix'      => $matrix,
            'on_demand'   => $onDemand,
            'by_engineer' => $byEngineer,
            'weekly'      => $weekly,
            'field_breakdowns' => $this->buildFieldBreakdowns($systemId, Device::whereIn('id', $deviceIds)->get(['id', 'custom_fields']), $activities->pluck('device_id')->unique()),
            'form_breakdowns'  => $this->buildFormBreakdowns($systemId, $activities, $activityTypes),
        ]);
    }

    /** Cobertura por valor de cada campo del directorio marcado para dashboard. */
    private function buildFieldBreakdowns(int $systemId, $devices, $coveredDeviceIds): array
    {
        $systemFields = SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)->where('show_in_dashboard', true)
            ->orderBy('sort_order')->get(['id', 'label', 'field_key', 'field_type']);

        $coveredSet = collect($coveredDeviceIds)->flip();
        $out = [];
        foreach ($systemFields as $field) {
            $key = $field->field_key;
            $groups = [];
            foreach ($devices as $dev) {
                $val = $dev->custom_fields[$key] ?? null;
                if ($val === null || $val === '') continue;
                $str = (string) $val;
                if (!isset($groups[$str])) $groups[$str] = ['total' => 0, 'covered' => 0];
                $groups[$str]['total']++;
                if ($coveredSet->has($dev->id)) $groups[$str]['covered']++;
            }
            if (count($groups) < 2) continue;
            $rows = collect($groups)->map(fn ($g, $label) => [
                'value'   => $label,
                'total'   => $g['total'],
                'covered' => $g['covered'],
                'pct'     => $g['total'] > 0 ? round($g['covered'] / $g['total'] * 100, 1) : 0,
            ])->sortByDesc('total')->values();
            $out[] = ['field_key' => $key, 'label' => $field->label, 'rows' => $rows];
        }
        return $out;
    }

    /** Distribución de valores de campos boolean/list del formulario de actividades. */
    private function buildFormBreakdowns(int $systemId, $activities, $activityTypes): array
    {
        $activityFields = ActivityTypeField::whereIn('activity_type_id', $activityTypes->pluck('id'))
            ->where('system_id', $systemId)
            ->whereIn('field_type', ['boolean', 'list', 'scale'])
            ->where('is_active', true)
            ->get(['id', 'activity_type_id', 'label', 'field_key', 'field_type']);

        $out = [];
        foreach ($activityFields as $field) {
            $typeActivities = $activities->where('activity_type_id', $field->activity_type_id);
            if ($typeActivities->isEmpty()) continue;
            $typeName = $activityTypes->firstWhere('id', $field->activity_type_id)?->label ?? '';

            if ($field->field_type === 'boolean') {
                $yes = $typeActivities->filter(fn ($a) => ($a->field_values[$field->field_key] ?? false) === true)->count();
                $no  = $typeActivities->filter(fn ($a) => isset($a->field_values[$field->field_key]) && $a->field_values[$field->field_key] === false)->count();
                if ($yes + $no === 0) continue;
                $out[] = [
                    'field_key' => $field->field_key, 'label' => $field->label, 'activity_type' => $typeName,
                    'type' => 'boolean', 'yes' => $yes, 'no' => $no, 'yes_pct' => round($yes / ($yes + $no) * 100, 1),
                ];
            } elseif ($field->field_type === 'list' || $field->field_type === 'scale') {
                $dist = [];
                foreach ($typeActivities as $act) {
                    $val = $act->field_values[$field->field_key] ?? null;
                    if ($val === null || $val === '') continue;
                    $dist[(string) $val] = ($dist[(string) $val] ?? 0) + 1;
                }
                if (count($dist) < 2) continue;
                arsort($dist);
                $out[] = [
                    'field_key' => $field->field_key, 'label' => $field->label, 'activity_type' => $typeName,
                    'type' => 'list',
                    'distribution' => collect($dist)->map(fn ($cnt, $val) => [
                        'value' => $val, 'count' => $cnt, 'pct' => round($cnt / array_sum($dist) * 100, 1),
                    ])->values(),
                ];
            }
        }
        return $out;
    }
}
