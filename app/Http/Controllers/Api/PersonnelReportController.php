<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ReportFilterSummary;
use App\Support\ReportSections;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Análisis de personal: qué hace cada quien y cuándo.
 *
 * Junta en un solo panorama lo que hoy vive disperso: capturas de mantenimiento, eventos
 * creados y atendidos, cambios de estado, comentarios y la propia bitácora del sistema.
 * Sirve para ver carga de trabajo, horarios reales y en qué se ocupa una persona —o el
 * equipo entero— en un periodo.
 *
 * **Es un reporte interno de gestión**, no de cliente: por eso se gobierna con su propio
 * permiso (`reports.personnel`) y no se recorta por cliente/sitio como los operativos.
 * Quien puede verlo, ve a todo el personal.
 *
 * ⚠️ Dos relojes distintos, a propósito:
 *  - **`at`** es la fecha de NEGOCIO (cuándo se hizo el trabajo): agrupa semanas y días.
 *  - **`ts`** es el instante REAL del registro (`created_at`): de aquí salen la hora del
 *    día y la jornada, porque `performed_at` en la mayoría de las capturas viene a las
 *    00:00 —es una fecha, no una marca de tiempo— y usarlo daría «todos trabajan a
 *    medianoche».
 *
 * `ts` se convierte a `WorkCalendar::TZ`: la base guarda en UTC y sin convertir la
 * jornada sale corrida 6 horas.
 */
class PersonnelReportController extends Controller
{
    /** La zona en la que de verdad trabaja la gente; la base guarda en UTC. */
    private const TZ = \App\Support\WorkCalendar::TZ;

    private const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    /** GET /reports/personnel */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('reports.personnel'), 403);

        [$from, $to, $userIds] = $this->context($request);

        $activities = $this->activities($from, $to, $userIds);
        $events     = $this->events($from, $to, $userIds);
        $history    = $this->history($from, $to, $userIds);
        $comments   = $this->comments($from, $to, $userIds);
        $logs       = $this->logs($from, $to, $userIds);

        $people = $this->people($activities, $events, $history, $comments, $logs);

        $payload = [
            'summary'    => $this->summary($people, $activities, $events, $history, $comments, $logs),
            'by_person'  => $people,
            'by_hour'    => $this->byHour($activities, $events, $history, $comments),
            'by_weekday' => $this->byWeekday($activities, $events, $history, $comments),
            'weekly'     => $this->weekly($activities, $events, $history, $comments),
            'by_activity_type' => $this->countBy($activities, 'type_label'),
            'by_event_type'    => $this->countBy($events, 'type_label'),
            'by_client'  => $this->countBy($activities->concat($events), 'client_name'),
            'by_site'    => $this->countBy($activities->concat($events), 'site_name'),
            'by_module'  => $this->countBy($logs, 'module_label'),
            'by_action'  => $this->countBy($logs, 'action_label'),
            'filters'    => $this->filterOptions(),
        ];

        $hidden = ReportSections::hiddenFor($request->user(), 'personnel');

        $payload['printable_sections'] = ReportSections::printable('personnel', $hidden);

        return response()->json(ReportSections::stripPayload($payload, 'personnel', $hidden));
    }

    /** GET /reports/personnel/pdf */
    public function pdf(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('reports.personnel'), 403);

        $payload = $this->show($request)->getData(true);
        $hidden  = $payload['hidden_sections'] ?? [];
        $shows   = ReportSections::printFilter($hidden, $request->input('sections'));

        $s = $payload['summary'];

        $kpis = array_values(array_filter([
            $shows('kpi.people')    ? ['label' => 'Personas con actividad', 'value' => $this->num($s['people'])] : null,
            $shows('kpi.records')   ? ['label' => 'Registros en el periodo', 'value' => $this->num($s['records'])] : null,
            $shows('kpi.activities')? ['label' => 'Capturas de actividad', 'value' => $this->num($s['activities'])] : null,
            $shows('kpi.events')    ? ['label' => 'Eventos creados', 'value' => $this->num($s['events'])] : null,
        ]));

        $bars = fn (string $title, array $rows) => [
            'type'  => 'bars',
            'title' => $title,
            'rows'  => array_map(fn ($r) => ['label' => $r['label'] ?? '—', 'count' => $r['count'] ?? 0], $rows),
        ];

        $blocks = [];

        if ($shows('weekly') && $payload['weekly']) {
            $blocks[] = ['type' => 'timeline', 'title' => 'Registros por semana', 'rows' => $payload['weekly']];
        }

        if ($shows('by_hour')) {
            $blocks[] = ['type' => 'timeline', 'title' => 'Horarios de trabajo', 'rows' => array_map(
                fn ($r) => ['label' => $r['label'], 'month' => 'Hora del día', 'count' => $r['count']],
                $payload['by_hour'],
            )];
        }

        if ($shows('by_weekday'))      $blocks[] = $bars('Por día de la semana', $payload['by_weekday']);
        if ($shows('by_activity_type'))$blocks[] = $bars('Qué actividades registra', $payload['by_activity_type']);
        if ($shows('by_event_type'))   $blocks[] = $bars('Qué eventos levanta', $payload['by_event_type']);
        if ($shows('by_module'))       $blocks[] = $bars('En qué parte del sistema trabaja', $payload['by_module']);
        if ($shows('by_action'))       $blocks[] = $bars('Qué tipo de acciones hace', $payload['by_action']);
        if ($shows('by_client'))       $blocks[] = $bars('Para qué clientes', $payload['by_client']);
        if ($shows('by_site'))         $blocks[] = $bars('En qué sitios', $payload['by_site']);

        if ($shows('by_person') && $payload['by_person']) {
            $blocks[] = [
                'type'  => 'table',
                'title' => 'Detalle por persona',
                'cols'  => [
                    ['label' => 'Persona',     'w' => 44],
                    ['label' => 'Capturas',    'w' => 17, 'align' => 'R'],
                    ['label' => 'Eventos',     'w' => 16, 'align' => 'R'],
                    ['label' => 'Cambios',     'w' => 16, 'align' => 'R'],
                    ['label' => 'Dispos.',     'w' => 16, 'align' => 'R'],
                    ['label' => 'Sitios',      'w' => 14, 'align' => 'R'],
                    ['label' => 'Días',        'w' => 13, 'align' => 'R'],
                    ['label' => 'Jornada',     'w' => 26, 'align' => 'R'],
                    ['label' => 'Prom./día',   'w' => 18, 'align' => 'R'],
                ],
                'rows' => array_map(fn ($p) => [
                    $p['name'],
                    $this->num($p['activities']),
                    $this->num($p['events']),
                    $this->num($p['status_changes']),
                    $this->num($p['devices']),
                    $this->num($p['sites']),
                    $this->num($p['active_days']),
                    $p['shift'] ?? '—',
                    $p['per_day'] === null ? '—' : number_format($p['per_day'], 1),
                ], $payload['by_person']),
                'size' => 7,
            ];
        }

        if ($shows('filters_applied')) {
            $applied = ReportFilterSummary::rows($request, $payload, 'personnel');

            if ($applied) {
                $blocks[] = [
                    'type'  => 'table',
                    'title' => 'Filtros aplicados',
                    'cols'  => [['label' => 'Filtro', 'w' => 62], ['label' => 'Valor', 'w' => 128]],
                    'rows'  => $applied,
                    'size'  => 7,
                ];
            }
        }

        $binary = (new \App\Services\Pdf\DashboardPdf([
            'meta' => [
                'title'        => 'Análisis de personal',
                'period_label' => $this->periodLabel($request),
                'generated_at' => now()->toDateTimeString(),
            ],
            'kpis'   => $kpis,
            'blocks' => $blocks,
        ]))
            ->withSignature($request->input('signature'), $request->input('signature_align'))
            ->withBranding(Tenant::fromRequest($request))
            ->render();

        $name = \App\Support\PrintableName::build(
            'Analisis de Personal',
            null,
            $request->input('date_from'),
            $request->input('date_to'),
        );

        return response()->streamDownload(fn () => print($binary), $name, ['Content-Type' => 'application/pdf']);
    }

    // ── Contexto ──────────────────────────────────────────────────────────────

    /** @return array{0:Carbon,1:Carbon,2:array<int>} */
    private function context(Request $request): array
    {
        $data = $request->validate([
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date|after_or_equal:date_from',
            'user_ids'   => 'nullable|string',
            'role'       => 'nullable|string|max:60',
        ]);

        $from = Carbon::parse($data['date_from'] ?? now()->startOfMonth())->startOfDay();
        $to   = Carbon::parse($data['date_to'] ?? now())->endOfDay();

        $ids = collect(explode(',', (string) ($data['user_ids'] ?? '')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->values();

        // El rol acota el universo cuando no se eligieron personas puntuales.
        if ($ids->isEmpty() && ! empty($data['role'])) {
            $ids = DB::table('users')
                ->join('model_has_roles as mhr', fn ($j) => $j->on('mhr.model_id', '=', 'users.id')->where('mhr.model_type', \App\Models\User::class))
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('r.name', $data['role'])
                ->pluck('users.id');
        }

        return [$from, $to, $ids->all()];
    }

    private function periodLabel(Request $request): string
    {
        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->format('d/m/Y') : '…';

        return $request->filled('date_from') || $request->filled('date_to')
            ? $fmt($request->input('date_from')) . ' - ' . $fmt($request->input('date_to'))
            : 'Todo el periodo';
    }

    // ── Orígenes ──────────────────────────────────────────────────────────────

    /**
     * Capturas de actividad. Se agrupan por `performed_at` —la hora en que el ingeniero
     * dijo haberlo hecho— porque es lo que representa su jornada real; `created_at` sólo
     * dice cuándo sincronizó el teléfono.
     */
    private function activities(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return DB::table('maintenance_activities as ma')
            ->join('users as u', 'u.id', '=', 'ma.user_id')
            ->leftJoin('catalogs as at', 'at.id', '=', 'ma.activity_type_id')
            ->leftJoin('maintenances as m', 'm.id', '=', 'ma.maintenance_id')
            ->leftJoin('sites as s', 's.id', '=', 'm.site_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->whereBetween('ma.performed_at', [$from, $to])
            ->when($userIds, fn ($q) => $q->whereIn('ma.user_id', $userIds))
            ->select([
                'ma.user_id', 'u.name as user_name', 'ma.device_id', 'ma.performed_at as at',
                DB::raw('ma.created_at as ts'),
                DB::raw('at.label as type_label'), DB::raw('s.name as site_name'),
                DB::raw('coalesce(c.short_name, c.name) as client_name'),
            ])
            ->get();
    }

    private function events(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return DB::table('events as e')
            ->join('users as u', 'u.id', '=', 'e.created_by')
            ->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
            ->leftJoin('sites as s', 's.id', '=', 'e.site_id')
            ->leftJoin('clients as c', 'c.id', '=', 'e.client_id')
            ->whereRaw('coalesce(e.occurred_at, e.created_at) between ? and ?', [$from, $to])
            ->when($userIds, fn ($q) => $q->whereIn('e.created_by', $userIds))
            ->select([
                DB::raw('e.created_by as user_id'), 'u.name as user_name', 'e.device_id',
                DB::raw('coalesce(e.occurred_at, e.created_at) as at'),
                DB::raw('e.created_at as ts'),
                DB::raw('et.label as type_label'), DB::raw('s.name as site_name'),
                DB::raw('coalesce(c.short_name, c.name) as client_name'),
            ])
            ->get();
    }

    private function history(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return DB::table('event_status_history as h')
            ->join('users as u', 'u.id', '=', 'h.user_id')
            ->whereBetween('h.created_at', [$from, $to])
            ->when($userIds, fn ($q) => $q->whereIn('h.user_id', $userIds))
            ->select(['h.user_id', 'u.name as user_name', 'h.created_at as at', DB::raw('h.created_at as ts')])
            ->get();
    }

    private function comments(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return DB::table('event_comments as ec')
            ->join('users as u', 'u.id', '=', 'ec.user_id')
            ->whereBetween('ec.created_at', [$from, $to])
            ->when($userIds, fn ($q) => $q->whereIn('ec.user_id', $userIds))
            ->select(['ec.user_id', 'u.name as user_name', 'ec.created_at as at', DB::raw('ec.created_at as ts')])
            ->get();
    }

    /** La bitácora del sistema: es lo que dice en QUÉ trabaja, más allá de las capturas. */
    private function logs(Carbon $from, Carbon $to, array $userIds): Collection
    {
        return DB::table('activity_logs as al')
            ->join('users as u', 'u.id', '=', 'al.user_id')
            ->whereBetween('al.created_at', [$from, $to])
            ->when($userIds, fn ($q) => $q->whereIn('al.user_id', $userIds))
            ->select([
                'al.user_id', 'u.name as user_name', 'al.created_at as at', DB::raw('al.created_at as ts'),
                'al.module', 'al.action', 'al.source',
            ])
            ->get()
            ->map(function ($r) {
                $r->module_label = self::MODULES[$r->module] ?? ucfirst((string) $r->module);
                $r->action_label = self::ACTIONS[$r->action] ?? ucfirst((string) $r->action);

                return $r;
            });
    }

    private const MODULES = [
        'devices' => 'Directorio', 'events' => 'Eventos', 'activities' => 'Capturas',
        'maintenances' => 'Mantenimientos', 'auth' => 'Sesión', 'floor-plans' => 'Planos',
        'integrations' => 'Integraciones', 'users' => 'Usuarios', 'clients' => 'Clientes',
        'sites' => 'Sitios', 'roles' => 'Roles', 'catalogs' => 'Catálogos',
        'event-config' => 'Config. de eventos', 'system-config' => 'Config. de sistemas',
        'report-templates' => 'Plantillas de reporte', 'reports' => 'Reportes',
        'config' => 'Configuración', 'snapshots' => 'Respaldos', 'directories' => 'Directorios',
    ];

    private const ACTIONS = [
        'created' => 'Creó', 'updated' => 'Editó', 'deleted' => 'Eliminó',
        'login' => 'Inició sesión', 'logout' => 'Cerró sesión', 'exported' => 'Exportó',
        'action' => 'Otras acciones', 'archived' => 'Archivó', 'restored' => 'Restauró',
        'impersonate_start' => 'Entró como otro usuario', 'impersonate_stop' => 'Salió de suplantación',
    ];

    // ── Agregados ─────────────────────────────────────────────────────────────

    /**
     * Una fila por persona con todo junto.
     *
     * `shift` (jornada) sale del primer y último registro operativo de cada día, promediado:
     * es la forma honesta de responder «a qué hora trabaja» sin pedirle a nadie que marque
     * entrada. Los días sin registros no cuentan.
     */
    private function people(Collection $activities, Collection $events, Collection $history, Collection $comments, Collection $logs): array
    {
        $rows = [];

        $bump = function (array &$rows, $r, string $field) {
            $id = (int) $r->user_id;
            $rows[$id] ??= [
                'user_id' => $id, 'name' => $r->user_name,
                'activities' => 0, 'events' => 0, 'status_changes' => 0, 'comments' => 0,
                'system_actions' => 0, 'devices' => [], 'sites' => [], 'days' => [], 'stamps' => [],
            ];
            $rows[$id][$field]++;
        };

        foreach ($activities as $r) {
            $bump($rows, $r, 'activities');
            $id = (int) $r->user_id;
            if ($r->device_id)  $rows[$id]['devices'][$r->device_id] = true;
            if ($r->site_name)  $rows[$id]['sites'][$r->site_name] = true;
            $this->stamp($rows[$id], $r->at, $r->ts ?? null);
        }

        foreach ($events as $r) {
            $bump($rows, $r, 'events');
            $id = (int) $r->user_id;
            if ($r->device_id) $rows[$id]['devices'][$r->device_id] = true;
            if ($r->site_name) $rows[$id]['sites'][$r->site_name] = true;
            $this->stamp($rows[$id], $r->at, $r->ts ?? null);
        }

        foreach ($history as $r)  { $bump($rows, $r, 'status_changes'); $this->stamp($rows[(int) $r->user_id], $r->at, $r->ts ?? null); }
        foreach ($comments as $r) { $bump($rows, $r, 'comments');       $this->stamp($rows[(int) $r->user_id], $r->at, $r->ts ?? null); }
        foreach ($logs as $r)     { $bump($rows, $r, 'system_actions'); }

        $out = [];

        foreach ($rows as $p) {
            $records = $p['activities'] + $p['events'] + $p['status_changes'] + $p['comments'];
            $days    = count($p['days']);

            $out[] = [
                'user_id'        => $p['user_id'],
                'name'           => $p['name'],
                'activities'     => $p['activities'],
                'events'         => $p['events'],
                'status_changes' => $p['status_changes'],
                'comments'       => $p['comments'],
                'system_actions' => $p['system_actions'],
                'records'        => $records,
                'devices'        => count($p['devices']),
                'sites'          => count($p['sites']),
                'active_days'    => $days,
                'per_day'        => $days ? round($records / $days, 2) : null,
                'shift'          => $this->shift($p['stamps']),
                'first_hour'     => $p['stamps'] ? min(array_column($p['stamps'], 'h')) : null,
                'last_hour'      => $p['stamps'] ? max(array_column($p['stamps'], 'h')) : null,
            ];
        }

        usort($out, fn ($a, $b) => $b['records'] <=> $a['records']);

        return $out;
    }

    /** El día activo se cuenta por la fecha de negocio; la hora, por el instante real. */
    private function stamp(array &$person, $at, $ts = null): void
    {
        $dia  = Carbon::parse($at)->toDateString();
        $hora = Carbon::parse($ts ?? $at)->setTimezone(self::TZ);

        $person['days'][$dia] = true;
        $person['stamps'][] = ['d' => $dia, 'h' => $hora->hour + $hora->minute / 60];
    }

    /** «08:12 - 17:40»: promedio del primer y del último registro de cada día activo. */
    private function shift(array $stamps): ?string
    {
        if (! $stamps) return null;

        $byDay = [];
        foreach ($stamps as $s) {
            $byDay[$s['d']]['min'] = min($byDay[$s['d']]['min'] ?? 24, $s['h']);
            $byDay[$s['d']]['max'] = max($byDay[$s['d']]['max'] ?? 0, $s['h']);
        }

        $starts = array_column($byDay, 'min');
        $ends   = array_column($byDay, 'max');

        return $this->hhmm(array_sum($starts) / count($starts)) . ' - ' . $this->hhmm(array_sum($ends) / count($ends));
    }

    private function hhmm(float $h): string
    {
        return sprintf('%02d:%02d', (int) $h, (int) round(($h - (int) $h) * 60));
    }

    private function summary(array $people, Collection $a, Collection $e, Collection $h, Collection $c, Collection $l): array
    {
        $hours = $this->byHour($a, $e, $h, $c);
        $peak  = collect($hours)->sortByDesc('count')->first();

        return [
            'people'      => count($people),
            'records'     => $a->count() + $e->count() + $h->count() + $c->count(),
            'activities'  => $a->count(),
            'events'      => $e->count(),
            'status_changes' => $h->count(),
            'comments'    => $c->count(),
            'system_actions' => $l->count(),
            'devices'     => $a->pluck('device_id')->merge($e->pluck('device_id'))->filter()->unique()->count(),
            'sites'       => $a->pluck('site_name')->merge($e->pluck('site_name'))->filter()->unique()->count(),
            'peak_hour'   => $peak && $peak['count'] > 0 ? $peak['label'] : null,
            'avg_per_person' => count($people) ? round(array_sum(array_column($people, 'records')) / count($people), 1) : 0,
        ];
    }

    /** @return array<int, array{label:string,count:int}> */
    private function byHour(Collection ...$sets): array
    {
        $counts = array_fill(0, 24, 0);

        foreach ($sets as $set) {
            foreach ($set as $r) $counts[(int) Carbon::parse($r->ts ?? $r->at)->setTimezone(self::TZ)->hour]++;
        }

        return array_map(fn ($h) => ['label' => sprintf('%02d:00', $h), 'count' => $counts[$h]], range(0, 23));
    }

    private function byWeekday(Collection ...$sets): array
    {
        $counts = array_fill(0, 7, 0);

        foreach ($sets as $set) {
            foreach ($set as $r) $counts[(int) Carbon::parse($r->at)->dayOfWeek]++;
        }

        // Se muestra de lunes a domingo, que es como se lee una semana de trabajo.
        return array_map(
            fn ($i) => ['label' => self::DAYS[$i], 'count' => $counts[$i]],
            [1, 2, 3, 4, 5, 6, 0],
        );
    }

    private function weekly(Collection ...$sets): array
    {
        $weeks = [];

        foreach ($sets as $set) {
            foreach ($set as $r) {
                $k = Carbon::parse($r->at)->startOfWeek()->toDateString();
                $weeks[$k] = ($weeks[$k] ?? 0) + 1;
            }
        }

        ksort($weeks);

        return collect($weeks)->map(fn ($n, $k) => [
            'label' => Carbon::parse($k)->format('d/m'),
            'month' => Carbon::parse($k)->locale('es')->isoFormat('MMM YY'),
            'count' => $n,
        ])->values()->all();
    }

    /** @return array<int, array{label:string,count:int}> */
    private function countBy(Collection $set, string $field): array
    {
        return $set->groupBy(fn ($r) => $r->{$field} ?: '—')
            ->map(fn ($g, $k) => ['label' => (string) $k, 'count' => $g->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function filterOptions(): array
    {
        return [
            // Los ARCHIVADOS también: el reporte mira hacia atrás y su trabajo cuenta.
            // Se marcan para que nadie los confunda con personal activo al filtrar.
            'users' => DB::table('users')
                ->orderBy('name')
                ->get(['id', 'name', 'deleted_at'])
                ->map(fn ($u) => [
                    'id'       => $u->id,
                    'name'     => $u->name . ($u->deleted_at ? ' (archivado)' : ''),
                    'archived' => (bool) $u->deleted_at,
                ]),
            'roles' => DB::table('roles')->orderBy('name')->pluck('name'),
        ];
    }

    private function num(int|float|null $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
}
