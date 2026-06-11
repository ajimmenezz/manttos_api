<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\SystemField;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MaintenanceDashboardController extends Controller
{
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

        // field_FIELDKEY=value  →  ['fieldKey' => 'value', ...]
        $fieldFilters = collect($request->all())
            ->filter(fn ($v, $k) => str_starts_with($k, 'field_') && $v !== '' && $v !== null)
            ->mapWithKeys(fn ($v, $k) => [substr($k, 6) => $v]);

        // ── Dispositivos del sistema en este sitio (aplicar filtros de campo) ─
        $deviceQuery = Device::whereHas('directory', fn ($q) =>
            $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
        )->where('is_active', true);

        foreach ($fieldFilters as $key => $value) {
            $deviceQuery->whereRaw("custom_fields->>? = ?", [$key, $value]);
        }

        $deviceIds    = $deviceQuery->pluck('id');
        $totalDevices = $deviceIds->count();

        // ── Actividades del mantenimiento (filtradas por dispositivo y fecha) ──
        $actQuery = MaintenanceActivity::where('maintenance_id', $maintId)
            ->select('id', 'device_id', 'activity_type_id', 'user_id', 'field_values', 'performed_at')
            ->whereIn('device_id', $deviceIds);

        if ($dateFrom) $actQuery->where('performed_at', '>=', $dateFrom);
        if ($dateTo)   $actQuery->where('performed_at', '<=', $dateTo);

        $activities = $actQuery->get();

        $totalActivities   = $activities->count();
        $coveredDeviceIds  = $activities->pluck('device_id')->unique();
        $coveredCount      = $coveredDeviceIds->count();

        // Tipos de actividad activos y vinculados al sistema
        $linkedTypeIds = DB::table('activity_type_systems')
            ->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)
            ->where('is_active', true)
            ->get(['id', 'label']);

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
        $systemFields = SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->where('show_in_dashboard', true)
            ->orderBy('sort_order')
            ->get(['id', 'label', 'field_key', 'field_type']);

        // Cargar custom_fields de todos los dispositivos activos
        $devices = Device::whereIn('id', $deviceIds)
            ->select('id', 'custom_fields')
            ->get();

        $coveredSet = $coveredDeviceIds->flip(); // flip for O(1) lookup

        $fieldBreakdowns = [];
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

            $fieldBreakdowns[] = [
                'field_key' => $key,
                'label'     => $field->label,
                'rows'      => $rows,
            ];
        }

        // ── Distribución de campos de formulario de actividades ───────────────
        $activityFields = \App\Models\ActivityTypeField::whereIn('activity_type_id', $activityTypes->pluck('id'))
            ->where('system_id', $systemId)
            ->whereIn('field_type', ['boolean', 'list'])
            ->where('is_active', true)
            ->get(['id', 'activity_type_id', 'label', 'field_key', 'field_type']);

        $formBreakdowns = [];
        foreach ($activityFields as $field) {
            $typeActivities = $activities->where('activity_type_id', $field->activity_type_id);
            if ($typeActivities->isEmpty()) continue;

            if ($field->field_type === 'boolean') {
                $yes = $typeActivities->filter(fn ($a) => ($a->field_values[$field->field_key] ?? false) === true)->count();
                $no  = $typeActivities->filter(fn ($a) => isset($a->field_values[$field->field_key]) && $a->field_values[$field->field_key] === false)->count();
                if ($yes + $no === 0) continue;
                $typeName = $activityTypes->firstWhere('id', $field->activity_type_id)?->label ?? '';
                $formBreakdowns[] = [
                    'field_key'         => $field->field_key,
                    'label'             => $field->label,
                    'activity_type'     => $typeName,
                    'type'              => 'boolean',
                    'yes'               => $yes,
                    'no'                => $no,
                    'yes_pct'           => round($yes / ($yes + $no) * 100, 1),
                ];
            } elseif ($field->field_type === 'list') {
                $dist = [];
                foreach ($typeActivities as $act) {
                    $val = $act->field_values[$field->field_key] ?? null;
                    if ($val === null || $val === '') continue;
                    $dist[(string) $val] = ($dist[(string) $val] ?? 0) + 1;
                }
                if (count($dist) < 2) continue;
                arsort($dist);
                $typeName = $activityTypes->firstWhere('id', $field->activity_type_id)?->label ?? '';
                $formBreakdowns[] = [
                    'field_key'     => $field->field_key,
                    'label'         => $field->label,
                    'activity_type' => $typeName,
                    'type'          => 'list',
                    'distribution'  => collect($dist)->map(fn ($cnt, $val) => [
                        'value' => $val, 'count' => $cnt,
                        'pct'   => round($cnt / array_sum($dist) * 100, 1),
                    ])->values(),
                ];
            }
        }

        // ── Opciones de filtro por campo (valores únicos dentro del scope actual) ─
        $filterOptions = [];
        foreach ($systemFields as $field) {
            $key    = $field->field_key;
            $unique = $devices
                ->map(fn ($d) => (isset($d->custom_fields[$key]) && $d->custom_fields[$key] !== '')
                    ? (string) $d->custom_fields[$key]
                    : null
                )
                ->filter()
                ->unique()
                ->sort()
                ->values();

            if ($unique->isEmpty()) continue;

            $filterOptions[] = [
                'field_key' => $key,
                'label'     => $field->label,
                'values'    => $unique,
            ];
        }

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
            'filter_options'     => $filterOptions,
            'form_breakdowns'    => $formBreakdowns,
        ]);
    }
}
