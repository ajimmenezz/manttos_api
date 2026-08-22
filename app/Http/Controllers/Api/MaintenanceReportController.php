<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityTypeField;
use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\SystemField;
use App\Models\User;
use App\Support\ReportSections;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte GLOBAL de mantenimientos (uso interno). El grano es la **captura de
 * actividad** (`maintenance_activities`): cada fila es un trabajo capturado por un
 * ingeniero sobre un dispositivo, en un mantenimiento, con su fecha/hora de captura y
 * sus campos dinámicos. Permite analizar trabajos, horarios de captura, ingenieros,
 * sitios, sistemas, etc., con todos los filtros posibles + los campos dinámicos de cada
 * tipo de actividad. Modelado sobre EventDashboardController (mismo patrón de KPIs,
 * filtros dinámicos, tabla de detalle y export a Excel).
 *
 * Guardado por el permiso `maintenances.report` (asignable a roles internos).
 */
class MaintenanceReportController extends Controller
{
    /** Tipos agregables en los KPIs (los demás no aportan una distribución útil). */
    private const REPORTABLE_TYPES = ['boolean', 'list', 'multiselect', 'custom_list', 'custom_multiselect', 'scale', 'number', 'currency'];

    /** Tipos que nunca entran a columnas/filtros/KPIs. */
    private const SKIP_TYPES = ['image', 'signature', 'leyenda'];

    private const STATUS_LABELS = [
        'programado' => 'Programado', 'en_curso' => 'En curso',
        'completado' => 'Completado', 'cancelado' => 'Cancelado',
    ];
    private const TYPE_LABELS = ['normal' => 'Normal', 'contrato' => 'Contrato'];

    // ── Dashboard (KPIs) ──────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.report'), 403);

        [$activities, $formDefs, $dirDefs] = $this->collect($request);

        $total = $activities->count();

        $byActivityType = $activities->groupBy('activity_type_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->activity_type_id,
                'label' => optional($g->first()->activityType)->label ?? '—',
                'count' => $g->count(),
            ])->sortByDesc('count')->values();

        $bySystem = $activities->groupBy('system_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->system_id,
                'label' => optional(optional($g->first()->maintenance)->system)->label ?? '—',
                'count' => $g->count(),
            ])->sortByDesc('count')->values();

        $byClient = $activities->groupBy('client_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->client_id,
                'name'  => $this->clientName($g->first()),
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->take(12);

        $bySite = $activities->groupBy('site_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->site_id,
                'name'  => optional(optional($g->first()->maintenance)->site)->name ?? '—',
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->take(12);

        $byEngineer = $activities->groupBy('user_id')
            ->map(fn ($g) => [
                'id'    => $g->first()->user_id,
                'name'  => optional($g->first()->user)->name ?? '—',
                'count' => $g->count(),
            ])->sortByDesc('count')->values()->take(15);

        $byMaintenanceStatus = $activities->groupBy('maintenance_status')
            ->map(fn ($g, $k) => [
                'status' => $k,
                'label'  => self::STATUS_LABELS[$k] ?? ($k ?: '—'),
                'count'  => $g->count(),
            ])->sortByDesc('count')->values();

        // Serie semanal por fecha de captura.
        $weekly = $activities->groupBy(fn ($a) => Carbon::parse($a->performed_at)->startOfWeek()->toDateString())
            ->map(fn ($g, $week) => [
                'week_start' => $week,
                'label'      => Carbon::parse($week)->isoFormat('DD MMM'),
                'count'      => $g->count(),
            ])->sortBy('week_start')->values();

        // Horarios de captura: distribución por hora del día (0–23).
        $byHour = collect(range(0, 23))->map(fn ($h) => [
            'hour'  => $h,
            'label' => sprintf('%02d:00', $h),
            // La hora sale de `created_at`, NO de `performed_at`: éste es la FECHA de
            // trabajo y en la mayoría de las capturas viene a las 00:00, así que la
            // gráfica reportaba casi todo a medianoche. Y se convierte a la zona de
            // trabajo, porque la base guarda en UTC y salían corridas 6 horas.
            'count' => $activities->filter(fn ($a) => (int) Carbon::parse($a->created_at)
                ->setTimezone(\App\Support\WorkCalendar::TZ)->hour === $h)->count(),
        ])->values();

        $payload = [
            'summary' => [
                'total'       => $total,
                'devices'     => $activities->pluck('device_id')->filter()->unique()->count(),
                'maintenances'=> $activities->pluck('maintenance_id')->unique()->count(),
                'engineers'   => $activities->pluck('user_id')->filter()->unique()->count(),
                'sites'       => $activities->pluck('site_id')->filter()->unique()->count(),
                'clients'     => $activities->pluck('client_id')->filter()->unique()->count(),
            ],
            'by_activity_type'      => $byActivityType,
            'by_system'             => $bySystem,
            'by_client'             => $byClient,
            'by_site'               => $bySite,
            'by_engineer'           => $byEngineer,
            'by_maintenance_status' => $byMaintenanceStatus,
            'weekly'                => $weekly,
            'by_hour'               => $byHour,
            'form_breakdowns'       => $this->buildFormBreakdowns($activities, $formDefs),
            'directory_breakdowns'  => $this->buildDirectoryBreakdowns($activities, $dirDefs),
            'filters' => array_merge(
                $this->filterOptions($request),
                [
                    'fields'     => $this->buildFieldFilterMeta($activities, $formDefs),
                    'dir_fields' => $this->buildDirFilterMeta($activities, $dirDefs),
                ]
            ),
        ];

        // Recorte por rol/usuario: lo oculto ni siquiera viaja (ni a pantalla, ni a
        // impresión). Ver App\Support\ReportSections.
        $hidden = ReportSections::hiddenFor($request->user(), 'maintenances');

        // Ver la nota del reporte de eventos.
        // Los KPIs dinámicos dependen de qué campos marcó cada cliente, así que no caben
        // en el catálogo: se ofrecen uno por uno a partir de lo que trae este payload.
        $payload['printable_sections'] = array_merge(
            ReportSections::printable('maintenances', $hidden),
            \App\Support\BreakdownBlocks::options($payload['form_breakdowns'] ?? [], 'form', 'Campos del formulario'),
            \App\Support\BreakdownBlocks::options($payload['directory_breakdowns'] ?? [], 'directory', 'Datos del directorio'),
        );

        return response()->json(ReportSections::stripPayload($payload, 'maintenances', $hidden));
    }

    /**
     * GET /maintenances/report/pdf — el tablero dibujado por el servidor con FPDF,
     * respetando filtros y secciones ocultas por rol/usuario.
     */
    public function reportPdf(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('maintenances.report'), 403);

        $payload = $this->show($request)->getData(true);
        $hidden  = $payload['hidden_sections'] ?? [];

        // Qué se imprime: el recorte por rol/usuario y, encima, lo que se haya elegido en
        // el diálogo de descarga. Sin elección, todo lo visible (como siempre).
        $shows   = ReportSections::printFilter($hidden, $request->input('sections'));

        $s = $payload['summary'];
        $kpis = array_values(array_filter([
            $shows('kpi.total')     ? ['label' => 'Capturas de actividad', 'value' => number_format($s['total'], 0, ',', '.')] : null,
            $shows('kpi.devices')   ? ['label' => 'Dispositivos atendidos', 'value' => number_format($s['devices'], 0, ',', '.')] : null,
            $shows('kpi.engineers') ? ['label' => 'Ingenieros', 'value' => number_format($s['engineers'], 0, ',', '.')] : null,
            $shows('kpi.sites')     ? ['label' => 'Sitios', 'value' => number_format($s['sites'], 0, ',', '.')] : null,
        ]));

        $bars = fn (string $title, array $rows, string $labelKey = 'label') => [
            'type'  => 'bars',
            'title' => $title,
            'rows'  => array_map(fn ($r) => ['label' => $r[$labelKey] ?? '—', 'count' => $r['count'] ?? 0], $rows),
        ];

        $blocks = [];
        if ($shows('weekly') && $payload['weekly']) {
            $blocks[] = ['type' => 'timeline', 'title' => 'Capturas por semana', 'rows' => $payload['weekly']];
        }
        if ($shows('by_hour')) {
            $blocks[] = ['type' => 'timeline', 'title' => 'Horarios de captura', 'rows' => array_map(
                fn ($r) => ['label' => substr((string) $r['label'], 0, 2), 'month' => 'Hora del día', 'count' => $r['count']],
                $payload['by_hour'],
            )];
        }
        if ($shows('by_activity_type'))      $blocks[] = $bars('Por tipo de actividad', $payload['by_activity_type']);
        if ($shows('by_maintenance_status')) $blocks[] = $bars('Por estado del mantenimiento', $payload['by_maintenance_status']);
        if ($shows('by_system'))             $blocks[] = $bars('Por sistema', $payload['by_system']);
        if ($shows('rank_engineer'))         $blocks[] = $bars('Ranking por ingeniero', $payload['by_engineer'], 'name');
        if ($shows('rank_client'))           $blocks[] = $bars('Ranking por cliente', $payload['by_client'], 'name');
        if ($shows('rank_site'))             $blocks[] = $bars('Ranking por sitio', $payload['by_site'], 'name');

        // El listado de capturas NO va al PDF (ver la nota del reporte de eventos): para
        // el registro uno por uno están el Excel y la bitácora.

        $from = $request->input('date_from');
        $to   = $request->input('date_to');
        $fmt  = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '…';

        // KPIs dinámicos: van después de las gráficas fijas y antes del resumen de
        // filtros, que siempre cierra el documento.
        $blocks = array_merge(
            $blocks,
            \App\Support\BreakdownBlocks::build($payload['form_breakdowns'] ?? [], $shows, 'form'),
            \App\Support\BreakdownBlocks::build($payload['directory_breakdowns'] ?? [], $shows, 'directory'),
        );

        // Al final, qué recorte de datos representa el documento: un PDF archivado no
        // dice por sí solo si esas cifras eran de un sitio, de un cliente o de todo.
        if ($shows('filters_applied')) {
            $applied = \App\Support\ReportFilterSummary::rows($request, $payload, 'maintenances');

            if ($applied) {
                $blocks[] = [
                    'type'  => 'table',
                    'title' => 'Filtros aplicados',
                    'cols'  => [
                        ['label' => 'Filtro', 'w' => 62],
                        ['label' => 'Valor',  'w' => 128],
                    ],
                    'rows'  => $applied,
                    'size'  => 7,
                ];
            }
        }

        $binary = (new \App\Services\Pdf\DashboardPdf([
            'meta' => [
                'title'        => 'Reporte de mantenimientos',
                'period_label' => ($from || $to) ? $fmt($from) . ' - ' . $fmt($to) : 'Todo el periodo',
                'generated_at' => now()->toDateTimeString(),
            ],
            'kpis'   => $kpis,
            'blocks' => $blocks,
        ]))
            ->withSignature($request->input('signature'), $request->input('signature_align'))
            ->withBranding(\App\Support\Tenant::fromRequest($request))
            ->render();

        $name = \App\Support\PrintableName::build('Reporte Mantenimientos', null, $request->input('date_from'), $request->input('date_to'));

        return response()->streamDownload(fn () => print($binary), $name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ── Lista de detalle (tabla paginada) ─────────────────────────────────────

    public function reportList(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.report'), 403);
        $this->assertSectionVisible($request, 'detail');

        [$activities, $formDefs, $dirDefs] = $this->collect($request);
        $cols = $this->columnDefs($activities, $formDefs, $dirDefs);

        $columns = array_map(
            fn ($c) => ['key' => $c['key'], 'header' => $c['header'], 'group' => $c['group']],
            array_merge($cols['core'], $cols['form']->all(), $cols['dir']->all())
        );

        $perPage = min(200, max(10, (int) $request->input('per_page', 25)));
        $page    = max(1, (int) $request->input('page', 1));
        $total   = $activities->count();
        $rows    = $activities->slice(($page - 1) * $perPage, $perPage)->values()
            ->map(fn ($a) => ['id' => $a->id] + $this->rowValues($a, $cols))
            ->all();

        return response()->json([
            'columns'    => $columns,
            'rows'       => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    // ── Export a Excel ────────────────────────────────────────────────────────

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('maintenances.report'), 403);
        $this->assertSectionVisible($request, 'detail');
        app(\App\Support\ActivityLogger::class)->log('maintenances', 'exported', 'Exportó el reporte de mantenimientos (Excel)', ['source' => 'request']);

        [$activities, $formDefs, $dirDefs] = $this->collect($request);
        $cols = $this->columnDefs($activities, $formDefs, $dirDefs);

        $columns = array_merge($cols['core'], $cols['form']->all(), $cols['dir']->all());
        $headers = array_map(fn ($c) => $c['header'], $columns);
        $keys    = array_map(fn ($c) => $c['key'], $columns);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mantenimientos');
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

        foreach ($activities as $rowIdx => $a) {
            $vals = $this->rowValues($a, $cols);
            $row  = $rowIdx + 2;
            foreach ($keys as $i => $key) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1) . $row,
                    (string) ($vals[$key] ?? ''),
                    DataType::TYPE_STRING
                );
            }
        }
        foreach (range(1, max(1, count($headers))) as $ci) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }

        $writer   = new Xlsx($spreadsheet);
        $filename = 'reporte-mantenimientos_' . now()->format('Ymd_His') . '.xlsx';
        return response()->streamDownload(
            function () use ($writer) { $writer->save('php://output'); },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
             'Content-Disposition' => "attachment; filename=\"{$filename}\""]
        );
    }

    // ── Núcleo: cargar + filtrar el universo de capturas ──────────────────────

    /**
     * Devuelve [actividades filtradas, defs de campos de formulario, defs de directorio].
     * Las definiciones se calculan sobre el universo YA scopeado (para no colapsar las
     * opciones de filtro), y luego se aplican los filtros dinámicos.
     */
    private function collect(Request $request): array
    {
        $activities = $this->scopedActivities($request);

        // Defs a partir del universo scopeado (antes de aplicar filtros dinámicos).
        $formDefs = $this->formFieldDefs($activities);
        $dirDefs  = $this->directoryFieldDefs($activities);

        $formFilters = $this->parseJsonFilters($request, 'field_filters');
        if (! empty($formFilters)) {
            $activities = $activities->filter(fn ($a) => $this->matchesFormFilters($a, $formFilters, $formDefs))->values();
        }
        $dirFilters = $this->parseJsonFilters($request, 'dir_filters');
        if (! empty($dirFilters)) {
            $activities = $activities->filter(fn ($a) => $this->matchesDirFilters($a, $dirFilters, $dirDefs))->values();
        }

        return [$activities, $formDefs, $dirDefs];
    }

    /** Universo de capturas tras filtros escalares + scope por rol (sin filtros dinámicos). */
    private function scopedActivities(Request $request): Collection
    {
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->filled('date_to')   ? Carbon::parse($request->date_to)->endOfDay()     : null;

        $q = MaintenanceActivity::query()
            ->join('maintenances', 'maintenances.id', '=', 'maintenance_activities.maintenance_id')
            ->join('sites', 'sites.id', '=', 'maintenances.site_id')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->whereNull('maintenances.archived_at')
            ->whereNull('sites.deleted_at')
            ->whereNull('clients.deleted_at')
            ->when($request->filled('client_id'),         fn ($x) => $x->where('sites.client_id', $request->client_id))
            ->when($request->filled('site_id'),           fn ($x) => $x->where('maintenances.site_id', $request->site_id))
            ->when($request->filled('system_id'),         fn ($x) => $x->where('maintenances.catalog_id', $request->system_id))
            ->when($request->filled('activity_type_id'),  fn ($x) => $x->where('maintenance_activities.activity_type_id', $request->activity_type_id))
            ->when($request->filled('engineer_id'),       fn ($x) => $x->where('maintenance_activities.user_id', $request->engineer_id))
            ->when($request->filled('maintenance_id'),    fn ($x) => $x->where('maintenance_activities.maintenance_id', $request->maintenance_id))
            ->when($request->filled('maintenance_status'),fn ($x) => $x->where('maintenances.status', $request->maintenance_status))
            ->when($request->filled('maintenance_type'),  fn ($x) => $x->where('maintenances.type', $request->maintenance_type))
            ->when($dateFrom, fn ($x) => $x->where('maintenance_activities.performed_at', '>=', $dateFrom))
            ->when($dateTo,   fn ($x) => $x->where('maintenance_activities.performed_at', '<=', $dateTo));

        $this->scopeByRole($request, $q);

        return $q->with([
                'activityType:id,label',
                'user:id,name',
                'device:id,name,device_type,custom_fields,directory_id',
                'device.directory:id,name',
                'maintenance:id,site_id,catalog_id,status,type',
                'maintenance.site:id,name,client_id',
                'maintenance.site.client:id,name,short_name',
                'maintenance.system:id,label',
            ])
            ->orderByDesc('maintenance_activities.performed_at')
            ->select(
                'maintenance_activities.*',
                'sites.client_id as client_id',
                'maintenances.site_id as site_id',
                'maintenances.catalog_id as system_id',
                'maintenances.status as maintenance_status',
                'maintenances.type as maintenance_type'
            )
            ->get();
    }

    /**
     * Scope por rol sobre el join. superadmin/admin = todo; admin-cliente/sitio = su
     * alcance; cualquier otro rol (defensivo — el permiso es interno) = lo que capturó
     * o los mantenimientos donde está asignado como ingeniero.
     */
    private function scopeByRole(Request $request, $query): void
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-cliente')) {
            $query->whereIn('sites.client_id', $user->clientsAsAdmin()->pluck('clients.id'));
            return;
        }
        if ($user->hasRole('admin-sitio')) {
            $query->whereIn('maintenances.site_id', $user->sitesAsAdmin()->pluck('sites.id'));
            return;
        }
        $query->where(function ($q) use ($user) {
            $q->where('maintenance_activities.user_id', $user->id)
              ->orWhereIn('maintenances.id', function ($sub) use ($user) {
                  $sub->select('maintenance_id')->from('maintenance_engineers')->where('user_id', $user->id);
              });
        });
    }

    // ── Definiciones de campos dinámicos ──────────────────────────────────────

    /** Campos de formulario presentes en el universo, por (tipo de actividad × sistema). Clave `atid:sysid:key`. */
    private function formFieldDefs(Collection $activities): Collection
    {
        $pairs = $activities->map(fn ($a) => $a->activity_type_id . ':' . $a->system_id)->unique();
        if ($pairs->isEmpty()) return collect();

        $atIds = $activities->pluck('activity_type_id')->unique()->filter();
        $sysIds = $activities->pluck('system_id')->unique()->filter();

        return ActivityTypeField::whereIn('activity_type_id', $atIds)
            ->whereIn('system_id', $sysIds)
            ->where('is_active', true)
            ->whereNotIn('field_type', self::SKIP_TYPES)
            ->with('activityType:id,label', 'system:id,label')
            ->orderBy('activity_type_id')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn ($f) => $pairs->contains($f->activity_type_id . ':' . $f->system_id))
            ->map(fn ($f) => [
                'key'              => $f->activity_type_id . ':' . $f->system_id . ':' . $f->field_key,
                'activity_type_id' => (int) $f->activity_type_id,
                'system_id'        => (int) $f->system_id,
                'field_key'        => $f->field_key,
                'field_type'       => $f->field_type,
                'label'            => $f->label,
                'type_label'       => optional($f->activityType)->label ?? '—',
                'system_label'     => optional($f->system)->label ?? '—',
                'config'           => $f->config ?? [],
            ])
            ->keyBy('key');
    }

    /** Campos del directorio (system_fields) de los sistemas con dispositivo. Clave `sys:sysid:key`. */
    private function directoryFieldDefs(Collection $activities): Collection
    {
        $sysIds = $activities->filter(fn ($a) => $a->device)->pluck('system_id')->unique()->values();
        if ($sysIds->isEmpty()) return collect();

        $labels = Catalog::whereIn('id', $sysIds)->pluck('label', 'id');
        return SystemField::whereIn('catalog_id', $sysIds)
            ->where('is_active', true)
            ->whereNotIn('field_type', self::SKIP_TYPES)
            ->orderBy('catalog_id')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn ($f) => [
                'key'          => 'sys:' . $f->catalog_id . ':' . $f->field_key,
                'system_id'    => (int) $f->catalog_id,
                'field_key'    => $f->field_key,
                'field_type'   => $f->field_type,
                'label'        => $f->label,
                'system_label' => $labels[$f->catalog_id] ?? '—',
                'config'       => $f->config ?? [],
            ])
            ->keyBy('key');
    }

    // ── Emparejamiento de filtros dinámicos (en memoria, AND) ─────────────────

    private function truthy($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private function matchesFormFilters($a, array $filters, Collection $defs): bool
    {
        foreach ($filters as $key => $cond) {
            $def = $defs->get($key);
            if (! $def) continue;
            if (! is_array($cond)) $cond = ['value' => $cond];

            // Un filtro de un (tipo×sistema) sólo restringe las actividades de ese par; las
            // demás pasan (igual que en el reporte de eventos).
            if ((int) $a->activity_type_id !== $def['activity_type_id'] || (int) $a->system_id !== $def['system_id']) {
                continue;
            }

            $fv   = ($a->field_values ?? [])[$def['field_key']] ?? null;
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
                if ($type === 'multiselect' || $type === 'custom_multiselect') {
                    $arr = is_array($fv) ? array_map('strval', $fv) : ($fv !== null && $fv !== '' ? [(string) $fv] : []);
                    if (! in_array((string) $wanted, $arr, true)) return false;
                } elseif ($type === 'boolean') {
                    if (($this->truthy($fv) ? '1' : '0') !== (string) $wanted) return false;
                } elseif (! empty($cond['contains'])) {
                    if ($fv === null || stripos((string) $fv, (string) $wanted) === false) return false;
                } else {
                    if ((string) ($fv ?? '') !== (string) $wanted) return false;
                }
            }
        }
        return true;
    }

    private function dirValue($a, array $def)
    {
        if ((int) $a->system_id !== $def['system_id'] || ! $a->device) return null;
        $cf = $a->device->custom_fields ?? [];
        return is_array($cf) ? ($cf[$def['field_key']] ?? null) : null;
    }

    private function matchesDirFilters($a, array $filters, Collection $defs): bool
    {
        foreach ($filters as $key => $cond) {
            $def = $defs->get($key);
            if (! $def) continue;
            if (! is_array($cond)) $cond = ['value' => $cond];
            $v    = $this->dirValue($a, $def);
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

    private function parseJsonFilters(Request $request, string $key): array
    {
        $raw = $request->input($key);
        if (is_string($raw)) $raw = json_decode($raw, true);
        return is_array($raw) ? $raw : [];
    }

    // ── Metadatos de filtros dinámicos ────────────────────────────────────────

    private function formSubset(Collection $activities, array $def): Collection
    {
        return $activities->filter(fn ($a) =>
            (int) $a->activity_type_id === $def['activity_type_id'] && (int) $a->system_id === $def['system_id']);
    }

    private function buildFieldFilterMeta(Collection $activities, Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset  = $this->formSubset($activities, $def);
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
                $nums = $subset->map(fn ($a) => ($a->field_values ?? [])[$def['field_key']] ?? null)
                    ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);
                if ($nums->isEmpty()) continue;
                $meta['min'] = $nums->min();
                $meta['max'] = $nums->max();
            } else {
                $labels = $this->optionLabels($def['config']);
                $vals = collect();
                foreach ($subset as $a) {
                    $v = ($a->field_values ?? [])[$def['field_key']] ?? null;
                    if ($v === null || $v === '') continue;
                    if ($type === 'boolean') {
                        $vals->push($this->truthy($v) ? '1' : '0');
                    } elseif (is_array($v)) {
                        foreach ($v as $x) if ($x !== null && $x !== '') $vals->push((string) $x);
                    } else {
                        $vals->push((string) $v);
                    }
                }
                $distinct = $vals->unique()->values();
                if ($distinct->isEmpty()) continue;
                // Alta cardinalidad → filtro "contiene"; si no, select con etiquetas.
                if ($type !== 'boolean' && $distinct->count() > 40) {
                    $meta['mode'] = 'text';
                } else {
                    $meta['mode']   = 'select';
                    $meta['values'] = $distinct->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()
                        ->map(fn ($v) => ['value' => $v, 'label' => $labels[$v] ?? $v])->all();
                }
            }
            $out[] = $meta;
        }
        return $out;
    }

    private function dirSubset(Collection $activities, array $def): Collection
    {
        return $activities->filter(fn ($a) => (int) $a->system_id === $def['system_id'] && $a->device);
    }

    private function buildDirFilterMeta(Collection $activities, Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            $subset = $this->dirSubset($activities, $def);
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
                $nums = $subset->map(fn ($a) => $this->dirValue($a, $def))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);
                if ($nums->isEmpty()) continue;
                $out[] = $meta + ['mode' => 'range', 'min' => $nums->min(), 'max' => $nums->max(), 'values' => []];
                continue;
            }

            $vals = collect();
            foreach ($subset as $a) {
                $v = $this->dirValue($a, $def);
                if ($v === null || $v === '' || is_array($v)) continue;
                $vals->push($type === 'boolean' ? ($this->truthy($v) ? '1' : '0') : (string) $v);
            }
            $distinct = $vals->unique()->values();
            if ($distinct->isEmpty()) continue;

            if ($type === 'boolean' || $distinct->count() <= 40) {
                $out[] = $meta + ['mode' => 'select', 'value_count' => $distinct->count(),
                    'values' => $distinct->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all()];
            } else {
                $out[] = $meta + ['mode' => 'text', 'value_count' => $distinct->count(), 'values' => []];
            }
        }
        return $out;
    }

    // ── KPIs de campos dinámicos ──────────────────────────────────────────────

    private function optionLabels($config): Collection
    {
        return collect(is_array($config) ? ($config['options'] ?? []) : [])
            ->filter(fn ($o) => isset($o['value']))
            ->mapWithKeys(fn ($o) => [(string) $o['value'] => (string) ($o['label'] ?? $o['value'])]);
    }

    private function aggregateOne(array $row, Collection $subset, callable $valueFn, string $type, $config = []): ?array
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

        // REGLA: un campo del DIRECTORIO identifica DÓNDE pasó algo (panel 1, lazo 5).
        // Sumarlo o promediarlo no significa nada —«promedio de panel 1.04» no es un dato—:
        // de estos campos sólo interesa CUÁNTOS registros hay en cada valor, así que se
        // tratan como reparto aunque el campo sea numérico.
        $countOnly = ($row['source'] ?? null) === 'directory';

        if (! $countOnly && in_array($type, ['number', 'currency'], true)) {
            $nums = $subset->map($valueFn)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v)->values();
            return $row + ['kind' => 'numeric', 'answered' => $nums->count(),
                'sum' => $nums->isEmpty() ? null : round($nums->sum(), 2),
                'avg' => $nums->isEmpty() ? null : round($nums->avg(), 2),
                'min' => $nums->isEmpty() ? null : $nums->min(),
                'max' => $nums->isEmpty() ? null : $nums->max(),
                'unit'     => is_array($config) ? ($config['unit'] ?? null) : null,
                'currency' => $type === 'currency' && is_array($config) ? ($config['currency'] ?? null) : null];
        }

        // distribución (list, multiselect, scale, custom_list, custom_multiselect)
        $labels = $this->optionLabels($config);
        $dist = []; $answered = 0;
        foreach ($subset as $e) {
            $v = $valueFn($e);
            if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) continue;
            $answered++;
            foreach ((is_array($v) ? $v : [$v]) as $x) {
                if ($x === null || $x === '') continue;
                $sx = $labels[(string) $x] ?? (string) $x;
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

    private function buildFormBreakdowns(Collection $activities, Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            if (! in_array($def['field_type'], self::REPORTABLE_TYPES, true)) continue;
            $subset = $this->formSubset($activities, $def);
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
            $agg = $this->aggregateOne($row, $subset, fn ($a) => ($a->field_values ?? [])[$fk] ?? null, $def['field_type'], $def['config'] ?? []);
            if ($agg) $out[] = $agg;
        }
        return $out;
    }

    private function buildDirectoryBreakdowns(Collection $activities, Collection $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            if (! in_array($def['field_type'], self::REPORTABLE_TYPES, true)) continue;
            $subset = $this->dirSubset($activities, $def);
            $row = [
                'key'          => $def['key'],
                'header'       => "{$def['system_label']} · {$def['label']}",
                'field_label'  => $def['label'],
                'system_label' => $def['system_label'],
                'type_label'   => null,
                'field_type'   => $def['field_type'],
                'source'       => 'directory',
            ];
            $agg = $this->aggregateOne($row, $subset, fn ($a) => $this->dirValue($a, $def), $def['field_type'], $def['config'] ?? []);
            if ($agg) $out[] = $agg;
        }
        return $out;
    }

    // ── Columnas + valores de la tabla / export ───────────────────────────────

    private const CORE_COLUMNS = [
        'cliente' => 'Cliente', 'sitio' => 'Sitio', 'sistema' => 'Sistema',
        'mantenimiento' => 'Mantenimiento', 'estado_mtto' => 'Estado del mantenimiento',
        'tipo_mtto' => 'Tipo de mantenimiento', 'tipo_actividad' => 'Tipo de actividad',
        'dispositivo' => 'Dispositivo', 'tipo_dispositivo' => 'Tipo de dispositivo', 'did' => 'DID',
        'directorio' => 'Directorio', 'capturado_por' => 'Capturado por',
        'fecha_captura' => 'Fecha de captura', 'hora_captura' => 'Hora de captura',
    ];

    private function columnDefs(Collection $activities, Collection $formDefs, Collection $dirDefs): array
    {
        $core = [];
        foreach (self::CORE_COLUMNS as $k => $h) $core[] = ['key' => 'core:' . $k, 'header' => $h, 'group' => 'core'];

        $form = $formDefs->values()->map(fn ($d) => [
            'key'              => 'form:' . $d['key'],
            'header'           => $d['type_label'] . ' · ' . $d['label'],
            'group'            => 'form',
            'activity_type_id' => $d['activity_type_id'],
            'system_id'        => $d['system_id'],
            'field_key'        => $d['field_key'],
            'field_type'       => $d['field_type'],
            'config'           => $d['config'] ?? [],
        ]);

        $dir = $dirDefs->values()->map(fn ($d) => [
            'key'        => 'dir:' . $d['system_id'] . ':' . $d['field_key'],
            'header'     => 'Directorio · ' . $d['system_label'] . ' · ' . $d['label'],
            'group'      => 'directory',
            'system_id'  => $d['system_id'],
            'field_key'  => $d['field_key'],
            'field_type' => $d['field_type'],
            'config'     => $d['config'] ?? [],
        ]);

        return ['core' => $core, 'form' => $form, 'dir' => $dir];
    }

    private function cellStr($v, string $type, $config = []): string
    {
        if ($v === null || $v === '') return '';
        if ($type === 'custom_list' || $type === 'custom_multiselect') {
            $labels = $this->optionLabels($config);
            $map = fn ($x) => $labels[(string) $x] ?? (string) $x;
            return is_array($v) ? implode(', ', array_map($map, $v)) : $map($v);
        }
        if (is_array($v)) return implode(', ', array_map('strval', $v));
        if ($type === 'boolean' || is_bool($v)) return $this->truthy($v) ? 'Sí' : 'No';
        return (string) $v;
    }

    private function clientName($a): string
    {
        $c = optional(optional($a->maintenance)->site)->client;
        return $c ? ($c->short_name ?: $c->name) : '—';
    }

    private function rowValues($a, array $cols): array
    {
        $cf     = ($a->device && is_array($a->device->custom_fields)) ? $a->device->custom_fields : [];
        $fv     = is_array($a->field_values) ? $a->field_values : [];
        $didKey = $this->didKeyForSystem((int) $a->system_id, $a->client_id ? (int) $a->client_id : null);
        $when   = $a->performed_at ? Carbon::parse($a->performed_at) : null;

        $out = [
            'core:cliente'          => $this->clientName($a),
            'core:sitio'            => optional(optional($a->maintenance)->site)->name ?? '',
            'core:sistema'          => optional(optional($a->maintenance)->system)->label ?? '',
            'core:mantenimiento'    => '#' . $a->maintenance_id,
            'core:estado_mtto'      => self::STATUS_LABELS[$a->maintenance_status] ?? (string) $a->maintenance_status,
            'core:tipo_mtto'        => self::TYPE_LABELS[$a->maintenance_type] ?? (string) $a->maintenance_type,
            'core:tipo_actividad'   => optional($a->activityType)->label ?? '',
            'core:dispositivo'      => optional($a->device)->name ?? '',
            'core:tipo_dispositivo' => optional($a->device)->device_type ?? '',
            'core:did'              => (string) ($cf[$didKey] ?? ''),
            'core:directorio'       => optional(optional($a->device)->directory)->name ?? '',
            'core:capturado_por'    => optional($a->user)->name ?? '',
            'core:fecha_captura'    => $when ? $when->format('Y-m-d') : '',
            'core:hora_captura'     => $when ? $when->format('H:i') : '',
        ];
        foreach ($cols['form'] as $c) {
            $out[$c['key']] = ((int) $a->activity_type_id === $c['activity_type_id'] && (int) $a->system_id === $c['system_id'])
                ? $this->cellStr($fv[$c['field_key']] ?? null, $c['field_type'], $c['config'] ?? []) : '';
        }
        foreach ($cols['dir'] as $c) {
            $out[$c['key']] = ($a->device && (int) $a->system_id === $c['system_id'])
                ? $this->cellStr($cf[$c['field_key']] ?? null, $c['field_type'], $c['config'] ?? []) : '';
        }
        return $out;
    }

    /** Clave del campo DID del sistema (field_type='did', override por cliente); fallback 'did'. */
    private function didKeyForSystem(int $systemId, ?int $clientId): string
    {
        $did = SystemField::where('catalog_id', $systemId)
            ->where('field_type', 'did')
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)))
            ->orderByRaw('client_id is null')
            ->value('field_key');

        return $did ?: 'did';
    }

    // ── Opciones de los selects de filtro ─────────────────────────────────────

    private function filterOptions(Request $request): array
    {
        // Universo scopeado sin los filtros activos, para poblar los selects.
        $base = MaintenanceActivity::query()
            ->join('maintenances', 'maintenances.id', '=', 'maintenance_activities.maintenance_id')
            ->join('sites', 'sites.id', '=', 'maintenances.site_id')
            ->join('clients', 'clients.id', '=', 'sites.client_id')
            ->whereNull('maintenances.archived_at')
            ->whereNull('sites.deleted_at')
            ->whereNull('clients.deleted_at');
        $this->scopeByRole($request, $base);

        $rows = (clone $base)->distinct()->get([
            'sites.client_id as client_id',
            'maintenances.site_id as site_id',
            'maintenances.catalog_id as system_id',
            'maintenance_activities.activity_type_id as activity_type_id',
            'maintenance_activities.user_id as user_id',
        ]);

        $clientIds = $rows->pluck('client_id')->filter()->unique();
        $siteIds   = $rows->pluck('site_id')->filter()->unique();
        $sysIds    = $rows->pluck('system_id')->filter()->unique();
        $atIds     = $rows->pluck('activity_type_id')->filter()->unique();
        $userIds   = $rows->pluck('user_id')->filter()->unique();

        // Si hay cliente fijo, acota sitios a ese cliente (evita mostrar sitios de otros).
        if ($request->filled('client_id')) {
            $siteIds = $rows->where('client_id', (int) $request->client_id)->pluck('site_id')->filter()->unique();
        }

        $clients = \App\Models\Client::whereIn('id', $clientIds)->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->short_name ?: $c->name]);

        $sites = \App\Models\Site::whereIn('id', $siteIds)->orderBy('name')
            ->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        $systems = Catalog::whereIn('id', $sysIds)->orderBy('label')
            ->get(['id', 'label'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->label]);

        $activityTypes = Catalog::whereIn('id', $atIds)->orderBy('label')
            ->get(['id', 'label'])->map(fn ($t) => ['id' => $t->id, 'label' => $t->label]);

        $engineers = User::whereIn('id', $userIds)->orderBy('name')
            ->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        $statuses = collect(Maintenance::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => self::STATUS_LABELS[$s] ?? $s]);
        $types    = collect(Maintenance::TYPES)->map(fn ($t) => ['value' => $t, 'label' => self::TYPE_LABELS[$t] ?? $t]);

        return compact('clients', 'sites', 'systems', 'activityTypes', 'engineers', 'statuses', 'types');
    }

    /**
     * Las secciones ocultas para el rol/usuario tampoco se pueden pedir por endpoint
     * (detalle paginado, Excel). Ver App\Support\ReportSections.
     */
    private function assertSectionVisible(Request $request, string $section): void
    {
        abort_if(
            ReportSections::isHidden($request->user(), 'maintenances', $section),
            403,
            'Esta sección del reporte no está disponible para tu usuario.'
        );
    }

}
