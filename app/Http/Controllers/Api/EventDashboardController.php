<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventType;
use App\Support\EventSla;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->when($dateFrom, fn ($q) => $q->where('events.created_at', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('events.created_at', '<=', $dateTo));
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
                'history:id,event_id,to_status_id,created_at',
            ])
            ->get(['id', 'client_id', 'site_id', 'system_id', 'event_type_id', 'status_id', 'priority',
                   'impact', 'urgency', 'scheduled_attention_at', 'created_at']);

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

        // ── Serie semanal (por created_at) ────────────────────────────────────
        $weekly = $events->groupBy(fn ($e) => Carbon::parse($e->created_at)->startOfWeek()->toDateString())
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
            'filters'       => $this->filterOptions($request),
        ]);
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

        $sites = Event::whereIn('id', $ids)->with('site:id,name')->get()
            ->pluck('site')->filter()->unique('id')
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->sortBy('name')->values();

        $systems = Event::whereIn('id', $ids)->with('system:id,label')->get()
            ->pluck('system')->filter()->unique('id')
            ->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])
            ->sortBy('label')->values();

        $types = EventType::orderBy('label')->get(['id', 'label', 'nature'])
            ->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'nature' => $t->nature]);

        $statuses = EventStatus::where('is_active', true)->orderBy('sort_order')->get(['id', 'label', 'color']);

        return compact('clients', 'sites', 'systems', 'types', 'statuses');
    }
}
