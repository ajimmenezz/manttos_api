<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Models\Catalog;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventType;
use App\Models\EventTypeField;
use App\Models\FloorPlan;
use App\Models\SystemField;
use App\Support\EventSla;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventDashboardController extends Controller
{
    use ScopesEvents;

    private const PRIORITY_LABELS = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('events.view'), 403);

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        // ── Query base (scopeada por rol + filtros) ───────────────────────────
        $base = Event::query()
            ->when($request->filled('client_id'),     fn ($q) => $q->where('events.client_id', $request->client_id))
            ->when($request->filled('site_id'),       fn ($q) => $q->where('events.site_id', $request->site_id))
            ->when($request->filled('system_id'),     fn ($q) => $q->where('events.system_id', $request->system_id))
            ->when($request->filled('event_type_id'), fn ($q) => $q->where('events.event_type_id', $request->event_type_id))
            ->when($request->filled('status_id'),     fn ($q) => $q->where('events.status_id', $request->status_id))
            ->when($request->filled('priority'),      fn ($q) => $q->where('events.priority', $request->priority))
            ->when($dateFrom, fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) >= ?', [$dateFrom]))
            ->when($dateTo,   fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) <= ?', [$dateTo]));
        $this->scopeEvents($request, $base);

        // Filtro por naturaleza (incidente/solicitud) requiere join lógico vía tipo
        if ($request->filled('nature')) {
            $typeIds = EventType::where('nature', $request->nature)->pluck('id');
            $base->whereIn('events.event_type_id', $typeIds);
        }

        $events = (clone $base)
            ->with([
                'status:id,label,color,category_id,is_terminal',
                'status.category:id,label',
                'eventType:id,label,color,nature',
                'system:id,label',
                'client:id,name,short_name',
                'site:id,name',
                'device:id,custom_fields',
                'history:id,event_id,to_status_id,created_at',
            ])
            ->get(['id', 'client_id', 'site_id', 'system_id', 'event_type_id', 'device_id', 'status_id', 'priority',
                   'impact', 'urgency', 'scheduled_attention_at', 'occurred_at', 'created_at', 'field_values']);

        // ── Campos de formulario marcados para el reporte (KPI + filtro) ──────
        // Definiciones por (sistema × tipo × campo). Las opciones de filtro se
        // calculan ANTES de aplicar los filtros de campo (para no colapsarlas);
        // los KPIs sí reflejan los filtros aplicados.
        $reportFields = $this->reportFieldDefs();
        $fieldMeta    = $this->buildFieldFilterMeta($events, $reportFields);

        // Campos del directorio del dispositivo ligado: TODOS como filtro, marcados como KPI.
        $dirDefsAll    = $this->directoryFieldDefs($events, false);
        $dirDefsMarked = $this->directoryFieldDefs($events, true);
        $dirMeta       = $this->buildDirFilterMeta($events, $dirDefsAll);

        $fieldFilters = $this->parseFieldFilters($request);
        if (! empty($fieldFilters)) {
            $events = $events
                ->filter(fn ($e) => $this->eventMatchesFieldFilters($e, $fieldFilters, $reportFields))
                ->values();
        }
        $dirFilters = $this->parseDirFilters($request);
        if (! empty($dirFilters)) {
            $events = $events
                ->filter(fn ($e) => $this->eventMatchesDirFilters($e, $dirFilters, $dirDefsAll))
                ->values();
        }

        $total = $events->count();

        // ── Tiempo de resolución (primer paso a un estado terminal) ───────────
        $terminalIds = EventStatus::where('is_terminal', true)->pluck('id');
        $eventIds    = $events->pluck('id');
        $resolved = DB::table('event_status_history')
            ->join('events', 'events.id', '=', 'event_status_history.event_id')
            ->whereIn('event_status_history.to_status_id', $terminalIds)
            ->whereIn('events.id', $eventIds)
            ->select('events.id', 'events.created_at', DB::raw('MIN(event_status_history.created_at) as resolved_at'))
            ->groupBy('events.id', 'events.created_at')
            ->get();
        $avgResolutionDays = $resolved->isNotEmpty()
            ? round($resolved->avg(fn ($r) => Carbon::parse($r->created_at)->diffInHours(Carbon::parse($r->resolved_at)) / 24), 1)
            : null;
        $resolvedCount = $resolved->count();

        // ── Agregaciones ──────────────────────────────────────────────────────
        $byPriority = collect(array_keys(self::PRIORITY_LABELS))->map(fn ($p) => [
            'priority' => $p,
            'label'    => self::PRIORITY_LABELS[$p],
            'count'    => $events->where('priority', $p)->count(),
        ])->values();

        $byNature = $events->groupBy(fn ($e) => optional($e->eventType)->nature ?? 'sin_tipo')
            ->map(fn ($g, $k) => ['nature' => $k, 'count' => $g->count()])
            ->values();

        $byStatus = $events->groupBy('status_id')
            ->map(fn ($g) => [
                'id'       => $g->first()->status_id,
                'label'    => optional($g->first()->status)->label ?? '—',
                'color'    => optional($g->first()->status)->color ?? '#94a3b8',
                'category' => optional(optional($g->first()->status)->category)->label,
                'count'    => $g->count(),
            ])
            ->sortByDesc('count')->values();

        $byEventType = $events->groupBy('event_type_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->event_type_id,
                'label' => optional($g->first()->eventType)->label ?? '—',
                'color' => optional($g->first()->eventType)->color ?? '#94a3b8',
                'count' => $g->count(),
            ])
            ->sortByDesc('count')->values();

        $bySystem = $events->groupBy('system_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->system_id,
                'label' => optional($g->first()->system)->label ?? '—',
                'count' => $g->count(),
            ])
            ->sortByDesc('count')->values();

        $byClient = $events->groupBy('client_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->client_id,
                'name'  => optional($g->first()->client)->short_name ?: (optional($g->first()->client)->name ?? '—'),
                'count' => $g->count(),
            ])
            ->sortByDesc('count')->values()->take(12);

        $bySite = $events->groupBy('site_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->site_id,
                'name'  => optional($g->first()->site)->name ?? '—',
                'count' => $g->count(),
            ])
            ->sortByDesc('count')->values()->take(12);

        // ── Serie semanal (por fecha de ocurrencia, con fallback a created_at) ─
        $weekly = $events->groupBy(fn ($e) => Carbon::parse($e->occurred_at ?? $e->created_at)->startOfWeek()->toDateString())
            ->map(fn ($g, $week) => [
                'week_start' => $week,
                'label'      => Carbon::parse($week)->isoFormat('DD MMM'),
                'count'      => $g->count(),
            ])
            ->sortBy('week_start')->values();

        // Categorías de estado (Abierto/Resuelto/Cerrado…) para semáforo de reportería
        $byCategory = $events->groupBy(fn ($e) => optional(optional($e->status)->category)->label ?? 'Sin categoría')
            ->map(fn ($g, $k) => ['category' => $k, 'count' => $g->count()])
            ->sortByDesc('count')->values();

        // ── Distribución por impacto / urgencia ──────────────────────────────
        $byImpact = collect(Event::IMPACTS)->map(fn ($i) => [
            'impact' => $i, 'count' => $events->where('impact', $i)->count(),
        ])->push(['impact' => 'sin_definir', 'count' => $events->whereNull('impact')->count()])
          ->filter(fn ($r) => $r['count'] > 0)->values();

        $byUrgency = collect(Event::URGENCIES)->map(fn ($u) => [
            'urgency' => $u, 'count' => $events->where('urgency', $u)->count(),
        ])->push(['urgency' => 'sin_definir', 'count' => $events->whereNull('urgency')->count()])
          ->filter(fn ($r) => $r['count'] > 0)->values();

        // ── SLA: cumplimiento por nivel de atención (matriz Impacto×Urgencia) ─
        $slaTiers  = EventSla::tiers();
        $statusMap = EventStatus::whereNotNull('sla_tier_id')->pluck('sla_tier_id', 'id')->all();
        $counts    = ['met' => 0, 'breached' => 0, 'overdue' => 0, 'pending' => 0, 'scheduled' => 0, 'attended' => 0];
        $tierAgg   = [];
        foreach ($slaTiers as $t) {
            $tierAgg[$t->id] = ['key' => $t->key, 'label' => $t->label,
                'met' => 0, 'breached' => 0, 'overdue' => 0, 'pending' => 0];
        }
        $trackedCount = 0;
        foreach ($events as $ev) {
            $m = EventSla::measure($ev, EventSla::resolve($ev->client_id), $statusMap, $slaTiers);
            if (! ($m['tracked'] ?? false)) continue;
            $trackedCount++;
            if ($m['scheduled']) {
                $counts[$m['overall']] = ($counts[$m['overall']] ?? 0) + 1;
                continue;
            }
            foreach ($m['tiers'] as $row) {
                if (isset($tierAgg[$row['tier_id']][$row['state']])) $tierAgg[$row['tier_id']][$row['state']]++;
                $counts[$row['state']] = ($counts[$row['state']] ?? 0) + 1;
            }
        }
        $comp = fn ($met, $bad) => ($met + $bad) > 0 ? round($met / ($met + $bad) * 100) : null;
        $byTier = collect($tierAgg)->map(function ($t) use ($comp) {
            $bad = $t['breached'] + $t['overdue'];
            return $t + ['total' => $t['met'] + $bad + $t['pending'], 'compliance_pct' => $comp($t['met'], $bad)];
        })->values();
        $sla = [
            'tracked'        => $trackedCount,
            'counts'         => $counts,
            'compliance_pct' => $comp($counts['met'], $counts['breached'] + $counts['overdue']),
            'by_tier'        => $byTier,
        ];

        // ── KPIs de formulario + campos marcados sin datos (para el aviso) ────
        $formBreakdowns = $this->buildFormBreakdowns($events, $reportFields);
        $withDataKeys   = collect($formBreakdowns)->pluck('key');
        $pendingFields  = $reportFields
            ->reject(fn ($d) => $withDataKeys->contains($d['key']))
            ->map(fn ($d) => ['key' => $d['key'], 'header' => "{$d['system_label']} · {$d['type_label']} · {$d['label']}"])
            ->values();

        return response()->json([
            'summary' => [
                'total'               => $total,
                'incidentes'          => $events->filter(fn ($e) => optional($e->eventType)->nature === 'incidente')->count(),
                'solicitudes'         => $events->filter(fn ($e) => optional($e->eventType)->nature === 'solicitud')->count(),
                'resueltos'           => $resolvedCount,
                'abiertos'            => $total - $resolvedCount,
                'resolucion_pct'      => $total > 0 ? round($resolvedCount / $total * 100) : 0,
                'avg_resolution_days' => $avgResolutionDays,
            ],
            'by_priority'   => $byPriority,
            'by_nature'     => $byNature,
            'by_status'     => $byStatus,
            'by_category'   => $byCategory,
            'by_event_type' => $byEventType,
            'by_system'     => $bySystem,
            'by_client'     => $byClient,
            'by_site'       => $bySite,
            'by_impact'     => $byImpact,
            'by_urgency'    => $byUrgency,
            'weekly'        => $weekly,
            'sla'           => $sla,
            'form_breakdowns' => $formBreakdowns,
            'form_meta'       => ['marked' => $reportFields->count(), 'pending' => $pendingFields],
            'directory_breakdowns' => $this->buildDirectoryBreakdowns($events, $dirDefsMarked),
            'directory_meta'  => [
                'marked' => SystemField::where('show_in_event_report', true)->where('is_active', true)->count(),
                'linked' => $events->filter(fn ($e) => $e->device)->count(),
            ],
            'filters'       => array_merge($this->filterOptions($request), ['fields' => $fieldMeta, 'dir_fields' => $dirMeta]),
        ]);
    }

    // ── Reporte por campos del formulario (KPI + filtros) ─────────────────────

    /** Definiciones de los campos marcados con show_in_report, por (sistema×tipo×campo). */
    private function reportFieldDefs(): \Illuminate\Support\Collection
    {
        return EventTypeField::query()
            ->where('show_in_report', true)
            ->where('is_active', true)
            ->whereIn('field_type', EventTypeField::REPORTABLE_TYPES)
            ->with(['eventType:id,label', 'system:id,label'])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn ($f) => [
                'key'           => $f->system_id . ':' . $f->event_type_id . ':' . $f->field_key,
                'system_id'     => (int) $f->system_id,
                'event_type_id' => (int) $f->event_type_id,
                'field_key'     => $f->field_key,
                'field_type'    => $f->field_type,
                'label'         => $f->label,
                'system_label'  => optional($f->system)->label ?? '—',
                'type_label'    => optional($f->eventType)->label ?? '—',
                'config'        => $f->config ?? [],
                'catalog_type'  => $f->catalog_type,
            ])
            ->keyBy('key');
    }

    private function parseFieldFilters(Request $request): array
    {
        $raw = $request->input('field_filters');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : [];
    }

    private function truthy($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    /** ¿El evento pasa TODOS los filtros de campo activos? (AND, como el resto). */
    private function eventMatchesFieldFilters($e, array $filters, \Illuminate\Support\Collection $defs): bool
    {
        foreach ($filters as $key => $cond) {
            $def = $defs->get($key);
            if (! $def) continue;                        // filtro sobre un campo ya no marcado → se ignora
            if (! is_array($cond)) $cond = ['value' => $cond];

            // El campo solo existe en su (sistema × tipo): otros eventos no aplican.
            if ((int) $e->system_id !== $def['system_id'] || (int) $e->event_type_id !== $def['event_type_id']) {
                return false;
            }

            $fv   = ($e->field_values ?? [])[$def['field_key']] ?? null;
            $type = $def['field_type'];

            if (in_array($type, ['number', 'currency'], true)) {
                $min = isset($cond['min']) && $cond['min'] !== '' ? (float) $cond['min'] : null;
                $max = isset($cond['max']) && $cond['max'] !== '' ? (float) $cond['max'] : null;
                if ($min === null && $max === null) continue;
                if (! is_numeric($fv)) return false;
                $num = (float) $fv;
                if ($min !== null && $num < $min) return false;
                if ($max !== null && $num > $max) return false;
            } else {
                $wanted = $cond['value'] ?? null;
                if ($wanted === null || $wanted === '') continue;
                if ($type === 'multiselect') {
                    $arr = is_array($fv) ? array_map('strval', $fv) : ($fv !== null && $fv !== '' ? [(string) $fv] : []);
                    if (! in_array((string) $wanted, $arr, true)) return false;
                } elseif ($type === 'boolean') {
                    if (($this->truthy($fv) ? '1' : '0') !== (string) $wanted) return false;
                } else { // list, scale
                    if ((string) ($fv ?? '') !== (string) $wanted) return false;
                }
            }
        }
        return true;
    }

    /** Solo los eventos que pertenecen al (sistema × tipo) de la definición. */
    private function subsetFor($events, array $def)
    {
        return $events->filter(fn ($e) =>
            (int) $e->system_id === $def['system_id'] && (int) $e->event_type_id === $def['event_type_id']);
    }

    /** Opciones de filtro por cada campo marcado (valores presentes en el universo scopeado). */
    private function buildFieldFilterMeta($events, \Illuminate\Support\Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset  = $this->subsetFor($events, $def);
            $type    = $def['field_type'];
            $numeric = in_array($type, ['number', 'currency'], true);
            $meta = [
                'key'          => $def['key'],
                'header'       => "{$def['system_label']} · {$def['type_label']} · {$def['label']}",
                'field_label'  => $def['label'],
                'system_label' => $def['system_label'],
                'type_label'   => $def['type_label'],
                'field_type'   => $type,
                'numeric'      => $numeric,
                'values'       => [],
            ];

            if ($numeric) {
                $nums = $subset->map(fn ($e) => ($e->field_values ?? [])[$def['field_key']] ?? null)
                    ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);
                if ($nums->isEmpty()) continue;
                $meta['min'] = $nums->min();
                $meta['max'] = $nums->max();
            } else {
                $vals = collect();
                foreach ($subset as $e) {
                    $v = ($e->field_values ?? [])[$def['field_key']] ?? null;
                    if ($v === null || $v === '') continue;
                    if ($type === 'boolean') {
                        $vals->push($this->truthy($v) ? '1' : '0');
                    } elseif (is_array($v)) {
                        foreach ($v as $x) if ($x !== null && $x !== '') $vals->push((string) $x);
                    } else {
                        $vals->push((string) $v);
                    }
                }
                $meta['values'] = $vals->unique()->sort()->values()->all();
                if (empty($meta['values'])) continue;
            }
            $out[] = $meta;
        }
        return $out;
    }

    /**
     * Agrega UN campo sobre su subconjunto de eventos. El DENOMINADOR es el total del
     * subconjunto: un campo agregado después sigue aplicando, así que los nulos/apagados
     * cuentan (boolean → "No"; distribución → "(Sin capturar)"; numérico → cobertura).
     * `$valueFn(evento)` extrae el valor (form_values o custom_fields del dispositivo).
     */
    private function aggregateOne(array $row, $subset, callable $valueFn, string $type, array $config = []): ?array
    {
        $total = $subset->count();
        if ($total === 0) return null;
        $row['total'] = $total;

        if ($type === 'boolean') {
            $yes = 0; $answered = 0;
            foreach ($subset as $e) {
                $v = $valueFn($e);
                if ($v !== null && $v !== '') $answered++;
                if ($this->truthy($v)) $yes++;
            }
            return $row + ['kind' => 'boolean', 'answered' => $answered,
                'yes' => $yes, 'no' => $total - $yes, 'yes_pct' => (int) round($yes / $total * 100)];
        }

        if (in_array($type, ['number', 'currency'], true)) {
            $nums = collect($subset)->map($valueFn)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v)->values();
            return $row + ['kind' => 'numeric', 'answered' => $nums->count(),
                'sum' => $nums->isEmpty() ? null : round($nums->sum(), 2),
                'avg' => $nums->isEmpty() ? null : round($nums->avg(), 2),
                'min' => $nums->isEmpty() ? null : $nums->min(),
                'max' => $nums->isEmpty() ? null : $nums->max(),
                'unit'     => $config['unit'] ?? null,
                'currency' => $type === 'currency' ? ($config['currency'] ?? null) : null];
        }

        // distribución (list, multiselect, scale, text, date, did)
        $dist = []; $answered = 0;
        foreach ($subset as $e) {
            $v = $valueFn($e);
            if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) continue;
            $answered++;
            foreach ((is_array($v) ? $v : [$v]) as $x) {
                if ($x === null || $x === '') continue;
                $sx = (string) $x;
                $dist[$sx] = ($dist[$sx] ?? 0) + 1;
            }
        }
        arsort($dist);
        $distribution = collect($dist)->map(fn ($c, $val) => [
            'value' => (string) $val, 'count' => $c, 'pct' => (int) round($c / $total * 100), 'missing' => false,
        ])->values()->all();
        $missing = $total - $answered;
        if ($missing > 0) {
            $distribution[] = ['value' => '(Sin capturar)', 'count' => $missing,
                'pct' => (int) round($missing / $total * 100), 'missing' => true];
        }
        return $row + ['kind' => 'distribution', 'answered' => $answered, 'distribution' => $distribution];
    }

    /** KPIs de los campos de formulario marcados (denominador = eventos del sistema×tipo). */
    private function buildFormBreakdowns($events, \Illuminate\Support\Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset = $this->subsetFor($events, $def);
            $fk = $def['field_key'];
            $row = [
                'key'          => $def['key'],
                'header'       => "{$def['system_label']} · {$def['type_label']} · {$def['label']}",
                'field_label'  => $def['label'],
                'system_label' => $def['system_label'],
                'type_label'   => $def['type_label'],
                'field_type'   => $def['field_type'],
                'source'       => 'form',
            ];
            $agg = $this->aggregateOne($row, $subset, fn ($e) => ($e->field_values ?? [])[$fk] ?? null, $def['field_type'], $def['config'] ?? []);
            if ($agg) $out[] = $agg;
        }
        return $out;
    }

    // ── Datos del directorio del dispositivo ligado ───────────────────────────

    /**
     * Definiciones de campos del directorio (system_fields) por sistema presente en los
     * eventos CON dispositivo. `$onlyMarked` = solo los marcados para KPI; si no, todos
     * (para filtros). Clave `sys:{system_id}:{field_key}`.
     */
    private function directoryFieldDefs($events, bool $onlyMarked): \Illuminate\Support\Collection
    {
        $sysIds = collect($events)->filter(fn ($e) => $e->device)->pluck('system_id')->unique()->values();
        if ($sysIds->isEmpty()) return collect();

        $labels = Catalog::whereIn('id', $sysIds)->pluck('label', 'id');
        $q = SystemField::whereIn('catalog_id', $sysIds)->where('is_active', true)
            ->whereNotIn('field_type', ['image']);
        if ($onlyMarked) $q->where('show_in_event_report', true);

        return $q->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($f) => [
                'key'          => 'sys:' . $f->catalog_id . ':' . $f->field_key,
                'system_id'    => (int) $f->catalog_id,
                'field_key'    => $f->field_key,
                'field_type'   => $f->field_type,
                'label'        => $f->label,
                'system_label' => $labels[$f->catalog_id] ?? '—',
            ])
            ->keyBy('key');
    }

    /** Valor del campo del directorio para un evento (custom_fields del dispositivo ligado). */
    private function dirValue($e, array $def)
    {
        if ((int) $e->system_id !== $def['system_id'] || ! $e->device) return null;
        $cf = $e->device->custom_fields ?? [];
        return is_array($cf) ? ($cf[$def['field_key']] ?? null) : null;
    }

    private function dirSubset($events, array $def)
    {
        return collect($events)->filter(fn ($e) => (int) $e->system_id === $def['system_id'] && $e->device);
    }

    /** Meta de filtros por campo del directorio: modo range (num) / select (baja card) / text (alta card). */
    private function buildDirFilterMeta($events, \Illuminate\Support\Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset = $this->dirSubset($events, $def);
            if ($subset->isEmpty()) continue;
            $type = $def['field_type'];
            $meta = [
                'key'          => $def['key'],
                'header'       => "{$def['system_label']} · {$def['label']}",
                'field_label'  => $def['label'],
                'system_label' => $def['system_label'],
                'field_type'   => $type,
            ];

            if ($type === 'number') {
                $nums = $subset->map(fn ($e) => $this->dirValue($e, $def))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);
                if ($nums->isEmpty()) continue;
                $out[] = $meta + ['mode' => 'range', 'min' => $nums->min(), 'max' => $nums->max(), 'values' => []];
                continue;
            }

            $vals = collect();
            foreach ($subset as $e) {
                $v = $this->dirValue($e, $def);
                if ($v === null || $v === '' || is_array($v)) continue;
                $vals->push($type === 'boolean' ? ($this->truthy($v) ? '1' : '0') : (string) $v);
            }
            $distinct = $vals->unique()->values();
            if ($distinct->isEmpty()) continue;

            if ($type === 'boolean' || $distinct->count() <= 40) {
                $out[] = $meta + ['mode' => 'select', 'value_count' => $distinct->count(),
                    'values' => $distinct->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all()];
            } else {
                // Alta cardinalidad (área/ubicación): filtro "contiene", sin volcar valores.
                $out[] = $meta + ['mode' => 'text', 'value_count' => $distinct->count(), 'values' => []];
            }
        }
        return $out;
    }

    private function parseDirFilters(Request $request): array
    {
        $raw = $request->input('dir_filters');
        if (is_string($raw)) $raw = json_decode($raw, true);
        return is_array($raw) ? $raw : [];
    }

    private function eventMatchesDirFilters($e, array $filters, \Illuminate\Support\Collection $defs): bool
    {
        foreach ($filters as $key => $cond) {
            $def = $defs->get($key);
            if (! $def) continue;
            if (! is_array($cond)) $cond = ['value' => $cond];
            $v    = $this->dirValue($e, $def);
            $type = $def['field_type'];

            if ($type === 'number') {
                $min = isset($cond['min']) && $cond['min'] !== '' ? (float) $cond['min'] : null;
                $max = isset($cond['max']) && $cond['max'] !== '' ? (float) $cond['max'] : null;
                if ($min === null && $max === null) continue;
                if (! is_numeric($v)) return false;
                $n = (float) $v;
                if ($min !== null && $n < $min) return false;
                if ($max !== null && $n > $max) return false;
            } else {
                $wanted = $cond['value'] ?? null;
                if ($wanted === null || $wanted === '') continue;
                if ($type === 'boolean') {
                    if (($this->truthy($v) ? '1' : '0') !== (string) $wanted) return false;
                } elseif (! empty($cond['contains'])) {
                    if ($v === null || stripos((string) $v, (string) $wanted) === false) return false;
                } else {
                    if ((string) ($v ?? '') !== (string) $wanted) return false;
                }
            }
        }
        return true;
    }

    /** KPIs de los campos del directorio marcados (denominador = eventos del sistema con dispositivo). */
    private function buildDirectoryBreakdowns($events, \Illuminate\Support\Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset = $this->dirSubset($events, $def);
            $row = [
                'key'          => $def['key'],
                'header'       => "{$def['system_label']} · {$def['label']}",
                'field_label'  => $def['label'],
                'system_label' => $def['system_label'],
                'type_label'   => null,
                'field_type'   => $def['field_type'],
                'source'       => 'directory',
            ];
            $agg = $this->aggregateOne($row, $subset, fn ($e) => $this->dirValue($e, $def), $def['field_type']);
            if ($agg) $out[] = $agg;
        }
        return $out;
    }

    // ── Lista de eventos (tabla de detalle + Excel) ───────────────────────────

    private const CORE_COLUMNS = [
        'folio' => 'Folio', 'tipo' => 'Tipo', 'naturaleza' => 'Naturaleza', 'cliente' => 'Cliente', 'sitio' => 'Sitio',
        'sistema' => 'Sistema', 'dispositivo' => 'Dispositivo', 'did' => 'DID', 'prioridad' => 'Prioridad',
        'impacto' => 'Impacto', 'urgencia' => 'Urgencia', 'estado' => 'Estado', 'categoria' => 'Categoría',
        'creado_por' => 'Creado por', 'ocurrencia' => 'Fecha ocurrencia', 'creacion' => 'Fecha creación',
        'resolucion' => 'Fecha resolución', 'descripcion' => 'Descripción',
    ];

    private function cellStr($v, string $type): string
    {
        if ($v === null || $v === '') return '';
        if (is_array($v)) return implode(', ', array_map('strval', $v));
        if ($type === 'boolean') return $this->truthy($v) ? 'Sí' : 'No';
        return (string) $v;
    }

    private function fmtDateStr($v): string
    {
        if (! $v) return '';
        try { return Carbon::parse($v)->format('Y-m-d H:i'); } catch (\Throwable) { return (string) $v; }
    }

    /** Eventos filtrados (base+scope+nature+field/dir filters) con relaciones para la lista. */
    private function filteredEventsForList(Request $request)
    {
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        $base = Event::query()
            ->when($request->filled('client_id'),     fn ($q) => $q->where('events.client_id', $request->client_id))
            ->when($request->filled('site_id'),       fn ($q) => $q->where('events.site_id', $request->site_id))
            ->when($request->filled('system_id'),     fn ($q) => $q->where('events.system_id', $request->system_id))
            ->when($request->filled('event_type_id'), fn ($q) => $q->where('events.event_type_id', $request->event_type_id))
            ->when($request->filled('status_id'),     fn ($q) => $q->where('events.status_id', $request->status_id))
            ->when($request->filled('priority'),      fn ($q) => $q->where('events.priority', $request->priority))
            ->when($dateFrom, fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) >= ?', [$dateFrom]))
            ->when($dateTo,   fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) <= ?', [$dateTo]));
        $this->scopeEvents($request, $base);
        if ($request->filled('nature')) {
            $base->whereIn('events.event_type_id', EventType::where('nature', $request->nature)->pluck('id'));
        }

        $events = (clone $base)
            ->with([
                'eventType:id,label,nature', 'system:id,label',
                'status:id,label,category_id', 'status.category:id,label',
                'client:id,name,short_name', 'site:id,name',
                'device:id,name,custom_fields', 'creator:id,name',
            ])
            ->orderByDesc('created_at')
            ->get(['id', 'folio', 'description', 'client_id', 'site_id', 'system_id', 'event_type_id',
                   'device_id', 'status_id', 'priority', 'impact', 'urgency', 'occurred_at', 'created_at', 'field_values']);

        $reportFields = $this->reportFieldDefs();
        $ff = $this->parseFieldFilters($request);
        if (! empty($ff)) $events = $events->filter(fn ($e) => $this->eventMatchesFieldFilters($e, $ff, $reportFields))->values();
        $dirDefs = $this->directoryFieldDefs($events, false);
        $df = $this->parseDirFilters($request);
        if (! empty($df)) $events = $events->filter(fn ($e) => $this->eventMatchesDirFilters($e, $df, $dirDefs))->values();

        return $events->values();
    }

    /** Columnas de la lista: core + campos de formulario presentes + campos del directorio. */
    private function reportColumnDefs($events): array
    {
        $core = [];
        foreach (self::CORE_COLUMNS as $k => $h) $core[] = ['key' => 'core:' . $k, 'header' => $h, 'group' => 'core'];

        $pairSet = $events->map(fn ($e) => $e->event_type_id . ':' . $e->system_id)->unique()->flip();
        $form = EventTypeField::whereIn('event_type_id', $events->pluck('event_type_id')->unique())
            ->whereIn('system_id', $events->pluck('system_id')->unique())
            ->where('is_active', true)->where('field_type', '!=', 'leyenda')
            ->with('eventType:id,label')->orderBy('event_type_id')->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($f) => $pairSet->has($f->event_type_id . ':' . $f->system_id))
            ->map(fn ($f) => [
                'key'       => 'form:' . $f->event_type_id . ':' . $f->system_id . ':' . $f->field_key,
                'header'    => (optional($f->eventType)->label ?? '—') . ' · ' . $f->label,
                'group'     => 'form',
                'type_id'   => (int) $f->event_type_id, 'system_id' => (int) $f->system_id,
                'field_key' => $f->field_key, 'field_type' => $f->field_type,
            ])->values();

        $devSysIds = $events->filter(fn ($e) => $e->device)->pluck('system_id')->unique();
        $dir = collect();
        if ($devSysIds->isNotEmpty()) {
            $sysLabels = Catalog::whereIn('id', $devSysIds)->pluck('label', 'id');
            $dir = SystemField::whereIn('catalog_id', $devSysIds)->where('is_active', true)
                ->whereNotIn('field_type', ['image'])->orderBy('catalog_id')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($f) => [
                    'key'       => 'dir:' . $f->catalog_id . ':' . $f->field_key,
                    'header'    => 'Directorio · ' . ($sysLabels[$f->catalog_id] ?? '—') . ' · ' . $f->label,
                    'group'     => 'directory',
                    'system_id' => (int) $f->catalog_id, 'field_key' => $f->field_key, 'field_type' => $f->field_type,
                ])->values();
        }

        return ['core' => $core, 'form' => $form, 'dir' => $dir];
    }

    private function resolvedMap($events)
    {
        $terminalIds = EventStatus::where('is_terminal', true)->pluck('id');
        return DB::table('event_status_history')
            ->whereIn('to_status_id', $terminalIds)->whereIn('event_id', $events->pluck('id'))
            ->select('event_id', DB::raw('MIN(created_at) as resolved_at'))
            ->groupBy('event_id')->pluck('resolved_at', 'event_id');
    }

    /** Valores de un evento por clave de columna (todo string). */
    private function eventRowValues($e, array $defs, $resolvedAt): array
    {
        $cf = ($e->device && is_array($e->device->custom_fields)) ? $e->device->custom_fields : [];
        $fv = is_array($e->field_values) ? $e->field_values : [];
        $PRI = self::PRIORITY_LABELS;
        $IMP = ['alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo'];
        $URG = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
        $NAT = ['incidente' => 'Incidente', 'solicitud' => 'Solicitud'];

        $out = [
            'core:folio'       => (string) $e->folio,
            'core:tipo'        => optional($e->eventType)->label ?? '',
            'core:naturaleza'  => $NAT[optional($e->eventType)->nature] ?? (optional($e->eventType)->nature ?? ''),
            'core:cliente'     => optional($e->client)->short_name ?: (optional($e->client)->name ?? ''),
            'core:sitio'       => optional($e->site)->name ?? '',
            'core:sistema'     => optional($e->system)->label ?? '',
            'core:dispositivo' => optional($e->device)->name ?? '',
            'core:did'         => (string) ($cf['did'] ?? ''),
            'core:prioridad'   => $PRI[$e->priority] ?? (string) $e->priority,
            'core:impacto'     => $IMP[$e->impact] ?? (string) ($e->impact ?? ''),
            'core:urgencia'    => $URG[$e->urgency] ?? (string) ($e->urgency ?? ''),
            'core:estado'      => optional($e->status)->label ?? '',
            'core:categoria'   => optional(optional($e->status)->category)->label ?? '',
            'core:creado_por'  => optional($e->creator)->name ?? '',
            'core:ocurrencia'  => $this->fmtDateStr($e->occurred_at ?? $e->created_at),
            'core:creacion'    => $this->fmtDateStr($e->created_at),
            'core:resolucion'  => $this->fmtDateStr($resolvedAt[$e->id] ?? null),
            'core:descripcion' => (string) $e->description,
        ];
        foreach ($defs['form'] as $c) {
            $out[$c['key']] = ($e->event_type_id === $c['type_id'] && $e->system_id === $c['system_id'])
                ? $this->cellStr($fv[$c['field_key']] ?? null, $c['field_type']) : '';
        }
        foreach ($defs['dir'] as $c) {
            $out[$c['key']] = ($e->device && $e->system_id === $c['system_id'])
                ? $this->cellStr($cf[$c['field_key']] ?? null, $c['field_type']) : '';
        }
        return $out;
    }

    /** GET /events/report-list — lista paginada + definición de columnas (tabla de detalle). */
    public function reportList(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('events.view'), 403);
        $events   = $this->filteredEventsForList($request);
        $defs     = $this->reportColumnDefs($events);
        $resolved = $this->resolvedMap($events);

        $columns = array_map(
            fn ($c) => ['key' => $c['key'], 'header' => $c['header'], 'group' => $c['group']],
            array_merge($defs['core'], $defs['form']->all(), $defs['dir']->all())
        );

        $perPage = min(200, max(10, (int) $request->input('per_page', 25)));
        $page    = max(1, (int) $request->input('page', 1));
        $total   = $events->count();
        $rows    = $events->slice(($page - 1) * $perPage, $perPage)->values()
            ->map(fn ($e) => ['id' => $e->id] + $this->eventRowValues($e, $defs, $resolved))
            ->all();

        return response()->json([
            'columns'    => $columns,
            'rows'       => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage))],
        ]);
    }

    /** GET /events/export — Excel (.xlsx) con la lista de eventos filtrados (mismas columnas). */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('events.view'), 403);
        $events   = $this->filteredEventsForList($request);
        $defs     = $this->reportColumnDefs($events);
        $resolved = $this->resolvedMap($events);

        $columns = array_merge($defs['core'], $defs['form']->all(), $defs['dir']->all());
        $headers = array_map(fn ($c) => $c['header'], $columns);
        $keys    = array_map(fn ($c) => $c['key'], $columns);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Eventos');
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        foreach ($events as $rowIdx => $e) {
            $vals = $this->eventRowValues($e, $defs, $resolved);
            $row  = $rowIdx + 2;
            foreach ($keys as $i => $key) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $row, (string) ($vals[$key] ?? ''), DataType::TYPE_STRING);
            }
        }
        foreach (range(1, max(1, count($headers))) as $ci) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }

        $writer   = new Xlsx($spreadsheet);
        $filename = 'reporte-eventos_' . now()->format('Ymd_His') . '.xlsx';
        return response()->streamDownload(
            function () use ($writer) { $writer->save('php://output'); },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
             'Content-Disposition' => "attachment; filename=\"{$filename}\""]
        );
    }

    /**
     * Vista de plano del reporte: para un sitio, sus planos con SOLO los dispositivos
     * sembrados que tienen eventos en el conjunto filtrado (respeta todos los filtros
     * del reporte). Cada dispositivo trae el resumen de su evento representativo (el más
     * reciente) para pintar relleno=color del tipo · anillo=color del estado.
     */
    public function planDevices(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('events.view'), 403);
        $request->validate(['site_id' => 'required|integer']);

        $events = $this->filteredEventsForPlan($request)->filter(fn ($e) => $e->device_id !== null);

        // Agregación por dispositivo (representativo = evento más reciente)
        $byDevice = $events->groupBy('device_id')->map(function ($grp) {
            $latest = $grp->sortByDesc(fn ($e) => $e->occurred_at ?? $e->created_at)->first();
            return [
                'count'        => $grp->count(),
                'event_type'   => $latest->eventType?->label,
                'event_color'  => $latest->eventType?->color ?? '#6b7280',
                'status_label' => $latest->status?->label,
                'status_color' => $latest->status?->color ?? '#6b7280',
                'priority'     => $latest->priority,
                'folio'        => $latest->folio,
            ];
        });

        $deviceIds = $byDevice->keys();

        $plans = FloorPlan::where('site_id', $request->site_id)
            ->where('is_active', true)
            ->with(['placements' => fn ($q) => $q
                ->whereIn('device_id', $deviceIds)
                ->with('device:id,name,device_type,custom_fields')])
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (FloorPlan $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'image_url'    => $p->image_url,
                'image_width'  => $p->image_width,
                'image_height' => $p->image_height,
                'placements'   => $p->placements->map(fn ($pl) => [
                    'device_id'     => $pl->device_id,
                    'x'             => (float) $pl->x,
                    'y'             => (float) $pl->y,
                    'name'          => $pl->device?->name,
                    'device_type'   => $pl->device?->device_type,
                    'custom_fields' => $pl->device?->custom_fields,
                    'events'        => $byDevice->get($pl->device_id),
                ])->values(),
            ]);

        return response()->json([
            'plans'         => $plans,
            'with_events'   => $deviceIds->count(),
            'total_events'  => $events->count(),
        ]);
    }

    /** Conjunto de eventos filtrado (mismo pipeline que show) para la vista de plano. */
    private function filteredEventsForPlan(Request $request)
    {
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        $base = Event::query()
            ->when($request->filled('client_id'),     fn ($q) => $q->where('events.client_id', $request->client_id))
            ->when($request->filled('site_id'),       fn ($q) => $q->where('events.site_id', $request->site_id))
            ->when($request->filled('system_id'),     fn ($q) => $q->where('events.system_id', $request->system_id))
            ->when($request->filled('event_type_id'), fn ($q) => $q->where('events.event_type_id', $request->event_type_id))
            ->when($request->filled('status_id'),     fn ($q) => $q->where('events.status_id', $request->status_id))
            ->when($request->filled('priority'),      fn ($q) => $q->where('events.priority', $request->priority))
            ->when($dateFrom, fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) >= ?', [$dateFrom]))
            ->when($dateTo,   fn ($q) => $q->whereRaw('COALESCE(events.occurred_at, events.created_at) <= ?', [$dateTo]));
        $this->scopeEvents($request, $base);

        if ($request->filled('nature')) {
            $typeIds = EventType::where('nature', $request->nature)->pluck('id');
            $base->whereIn('events.event_type_id', $typeIds);
        }

        $events = (clone $base)
            ->with(['status:id,label,color', 'eventType:id,label,color,nature', 'device:id,custom_fields'])
            ->get(['id', 'client_id', 'site_id', 'system_id', 'event_type_id', 'device_id', 'status_id',
                   'priority', 'folio', 'occurred_at', 'created_at', 'field_values']);

        $reportFields = $this->reportFieldDefs();
        $fieldFilters = $this->parseFieldFilters($request);
        if (! empty($fieldFilters)) {
            $events = $events->filter(fn ($e) => $this->eventMatchesFieldFilters($e, $fieldFilters, $reportFields))->values();
        }
        $dirDefsAll = $this->directoryFieldDefs($events, false);
        $dirFilters = $this->parseDirFilters($request);
        if (! empty($dirFilters)) {
            $events = $events->filter(fn ($e) => $this->eventMatchesDirFilters($e, $dirFilters, $dirDefsAll))->values();
        }

        return $events;
    }

    /** Opciones para los selects de filtro (derivadas del universo scopeado, sin los filtros activos). */
    private function filterOptions(Request $request): array
    {
        $scoped = Event::query();
        $this->scopeEvents($request, $scoped);
        $ids = (clone $scoped)->pluck('events.id');

        $clients = Event::whereIn('id', $ids)->with('client:id,name,short_name')->get()
            ->pluck('client')->filter()->unique('id')
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->short_name ?: $c->name])
            ->sortBy('name')->values();

        // Sitios y sistemas: si hay un client_id fijo (contexto de cliente), se acotan a
        // ese cliente para que el dropdown de sitios no muestre los de otros clientes.
        $ctxIds = (clone $scoped)
            ->when($request->filled('client_id'), fn ($q) => $q->where('events.client_id', $request->client_id))
            ->pluck('events.id');

        $sites = Event::whereIn('id', $ctxIds)->with('site:id,name')->get()
            ->pluck('site')->filter()->unique('id')
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->sortBy('name')->values();

        $systems = Event::whereIn('id', $ctxIds)->with('system:id,label')->get()
            ->pluck('system')->filter()->unique('id')
            ->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])
            ->sortBy('label')->values();

        $types = EventType::orderBy('label')->get(['id', 'label', 'nature'])
            ->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'nature' => $t->nature]);

        $statuses = EventStatus::where('is_active', true)->orderBy('sort_order')->get(['id', 'label', 'color']);

        return compact('clients', 'sites', 'systems', 'types', 'statuses');
    }
}
