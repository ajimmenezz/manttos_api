<?php

namespace App\Services\Reports;

use App\Models\Catalog;
use App\Models\Device;
use App\Models\Event;
use App\Models\EventType;
use App\Models\MaintenanceActivity;
use App\Models\Site;
use App\Models\SystemField;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporte EJECUTIVO de un sitio y periodo: mezcla en un solo tablero las **capturas
 * de actividad** de los mantenimientos (preventivo, pruebas, …) y los **eventos**
 * (correctivos), que en la operación son el mismo "servicio brindado" visto desde
 * dos módulos distintos.
 *
 * El grano es el SERVICIO: una captura de actividad o un evento, siempre atado a un
 * dispositivo del directorio. Por eso todas las agrupaciones (ubicación, área, panel,
 * tipo de dispositivo…) salen de los campos del directorio del dispositivo, y no de
 * catálogos aparte: cada sistema define sus propios campos y el reporte se adapta.
 *
 * `config` decide qué bloques salen y por qué campos se agrupa (ver `defaultConfig`).
 * Devuelve un arreglo plano listo para dibujar: quien renderiza (PDF o pantalla) no
 * vuelve a consultar nada.
 */
class ExecutiveReport
{
    /** Filas máximas por bloque de barras (el resto no cabe ni se lee). */
    private const ROWS = 11;

    /** Clave sintética del bloque "tipo de dispositivo" (no es campo del directorio). */
    public const DEVICE_TYPE = '__device_type__';

    public function __construct(
        private Site $site,
        private ?int $systemId,
        private Carbon $from,
        private Carbon $to,
        private array $config = [],
    ) {}

    // ── Configuración ─────────────────────────────────────────────────────────

    /**
     * Campos del directorio agrupables para este sitio+sistema (los que sirven para
     * un "total por X": texto y listas; nunca DID, imágenes ni firmas).
     */
    public function groupableFields(): Collection
    {
        $systemIds = $this->systemIds();
        if ($systemIds->isEmpty()) return collect();

        $fields = SystemField::whereIn('catalog_id', $systemIds)
            ->where('is_active', true)
            ->whereIn('field_type', ['text', 'list', 'custom_list', 'number'])
            // Plantilla base + la del cliente del sitio (nunca la de otro cliente).
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $this->site->client_id))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->unique('field_key')
            ->map(fn (SystemField $f) => [
                'field_key' => $f->field_key,
                'label'     => $f->label,
                'type'      => $f->field_type,
                'config'    => $f->config ?? [],
            ])
            ->values();

        return $fields->prepend([
            'field_key' => self::DEVICE_TYPE,
            'label'     => 'Tipo de dispositivo',
            'type'      => 'device_type',
            'config'    => [],
        ]);
    }

    /** Tipos de servicio disponibles: tipos de actividad del sistema + eventos. */
    public function availableServices(): array
    {
        $activityTypes = Catalog::where('type', 'activity_type')
            ->orderBy('label')
            ->get(['id', 'label', 'is_active'])
            ->map(fn ($c) => ['key' => 'activity:' . $c->id, 'label' => $c->label, 'source' => 'activity', 'is_active' => (bool) $c->is_active])
            ->values()->all();

        $eventTypes = EventType::orderBy('label')->get(['id', 'label'])
            ->map(fn ($t) => ['key' => 'event:' . $t->id, 'label' => $t->label, 'source' => 'event', 'is_active' => true])
            ->values()->all();

        return array_merge($activityTypes, [
            ['key' => 'event:*', 'label' => 'Todos los eventos', 'source' => 'event', 'is_active' => true],
        ], $eventTypes);
    }

    /**
     * Configuración por defecto: réplica del tablero que se venía armando a mano —
     * acumulados generales + una sección por tipo de servicio con datos en el periodo.
     * Agrupa por los dos primeros campos de texto del directorio (Ubicación, Área…).
     */
    public function defaultConfig(): array
    {
        $groupables = $this->suggestedGroupFields();

        $sections = [];
        foreach ($this->presentServices() as $svc) {
            $sections[] = [
                'key'            => $svc['key'],
                'title'          => $svc['section_title'],
                'enabled'        => true,
                'progress'       => true,
                'by_device_type' => true,
                'timeline'       => true,
                'group_by'       => $groupables,
            ];
        }

        return [
            'title'    => 'Resumen de servicios del sitio',
            'subtitle' => null,
            'summary'  => [
                'enabled'  => true,
                'group_by' => $groupables,
                'by_device_type' => true,
            ],
            'sections' => $sections,
        ];
    }

    /**
     * Campos que MEJOR agrupan este directorio, por cardinalidad: los que tienen entre
     * 2 y 60 valores distintos ordenados de mayor a menor. Evita el azar del orden de
     * la plantilla (un "Panel" numérico o un campo de un solo valor no dicen nada) y
     * acierta con los de ubicación, que es lo que se reporta. El usuario los cambia
     * desde el configurador.
     */
    public function suggestedGroupFields(int $take = 2): array
    {
        $devices = $this->devices();

        return $this->groupableFields()
            ->reject(fn ($f) => $f['field_key'] === self::DEVICE_TYPE || $f['type'] === 'number')
            ->map(function (array $f) use ($devices) {
                $distinct = $devices
                    ->map(fn ($d) => $this->valueOf($d, $f['field_key'], $f['config']))
                    ->reject(fn ($v) => $v === 'Sin dato')
                    ->unique()->count();

                return $f + ['distinct' => $distinct];
            })
            // Fuera los casi-identificadores (un valor por dispositivo no agrupa nada).
            ->filter(fn ($f) => $f['distinct'] >= 2 && $f['distinct'] <= max(2, $devices->count() * 0.6))
            ->sortByDesc('distinct')
            ->take($take)
            ->pluck('field_key')->values()->all();
    }

    /** Servicios con al menos un registro en el periodo (para la config automática). */
    private function presentServices(): array
    {
        $out = [];

        $byType = $this->activities()->groupBy('activity_type_id');
        foreach ($byType as $typeId => $rows) {
            $label = $rows->first()->activityType?->label ?? 'Actividad';
            $out[] = [
                'key'           => 'activity:' . $typeId,
                'label'         => $label,
                'section_title' => 'Servicios de ' . mb_strtolower($label) . ' realizados en el periodo',
            ];
        }

        if ($this->events()->isNotEmpty()) {
            $out[] = [
                'key'           => 'event:*',
                'label'         => 'Correctivo',
                'section_title' => 'Eventos correctivos atendidos en el periodo',
            ];
        }

        return $out;
    }

    // ── Universos ─────────────────────────────────────────────────────────────

    private ?Collection $activitiesCache = null;
    private ?Collection $eventsCache = null;
    private ?Collection $devicesCache = null;

    private function systemIds(): Collection
    {
        if ($this->systemId) return collect([$this->systemId]);

        return $this->site->directories()->where('is_active', true)->pluck('catalog_id')->unique()->values();
    }

    /** Dispositivos vigentes del sitio (y sistema, si se acotó). Universo del % de avance. */
    private function devices(): Collection
    {
        return $this->devicesCache ??= Device::query()
            ->join('directories', 'directories.id', '=', 'devices.directory_id')
            ->where('directories.site_id', $this->site->id)
            ->when($this->systemId, fn ($q) => $q->where('directories.catalog_id', $this->systemId))
            ->whereNull('devices.archived_at')
            ->get(['devices.id', 'devices.name', 'devices.device_type', 'devices.custom_fields']);
    }

    private function activities(): Collection
    {
        return $this->activitiesCache ??= MaintenanceActivity::query()
            ->join('maintenances', 'maintenances.id', '=', 'maintenance_activities.maintenance_id')
            ->where('maintenances.site_id', $this->site->id)
            ->when($this->systemId, fn ($q) => $q->where('maintenances.catalog_id', $this->systemId))
            ->whereNull('maintenances.archived_at')
            ->whereBetween('maintenance_activities.performed_at', [$this->from, $this->to])
            ->with(['activityType:id,label', 'device:id,name,device_type,custom_fields'])
            ->select('maintenance_activities.*')
            ->get();
    }

    private function events(): Collection
    {
        return $this->eventsCache ??= Event::query()
            ->where('site_id', $this->site->id)
            ->when($this->systemId, fn ($q) => $q->where('system_id', $this->systemId))
            ->whereNull('archived_at')
            ->whereRaw('COALESCE(occurred_at, created_at) BETWEEN ? AND ?', [$this->from, $this->to])
            ->with(['device:id,name,device_type,custom_fields', 'eventType:id,label'])
            ->get();
    }

    /**
     * Registros de un "servicio" (`activity:<id>`, `event:*` o `event:<id>`) como
     * filas homogéneas: fecha + dispositivo. A partir de aquí todo se agrega igual,
     * venga de mantenimiento o de un evento.
     */
    private function rowsFor(string $key): Collection
    {
        [$source, $id] = array_pad(explode(':', $key, 2), 2, null);

        if ($source === 'activity') {
            return $this->activities()
                ->when($id !== '*', fn ($c) => $c->where('activity_type_id', (int) $id))
                ->map(fn ($a) => [
                    'date'   => Carbon::parse($a->performed_at),
                    'device' => $a->device,
                ])->values();
        }

        return $this->events()
            ->when($id !== '*' && $id !== null, fn ($c) => $c->where('event_type_id', (int) $id))
            ->map(fn ($e) => [
                'date'   => Carbon::parse($e->occurred_at ?? $e->created_at),
                'device' => $e->device,
            ])->values();
    }

    private function labelFor(string $key): string
    {
        [$source, $id] = array_pad(explode(':', $key, 2), 2, null);

        if ($source === 'activity') {
            return Catalog::where('id', (int) $id)->value('label') ?? 'Actividad';
        }
        if ($id === '*' || $id === null) return 'Correctivo';

        return EventType::where('id', (int) $id)->value('label') ?? 'Evento';
    }

    // ── Agregaciones ──────────────────────────────────────────────────────────

    /** Valor legible de un campo del directorio para un dispositivo. */
    private function valueOf(?Device $device, string $fieldKey, array $fieldConfig = []): string
    {
        if (! $device) return 'Sin dato';

        if ($fieldKey === self::DEVICE_TYPE) {
            return $this->clean($device->device_type);
        }

        $raw = ($device->custom_fields ?? [])[$fieldKey] ?? null;
        if (is_array($raw)) $raw = implode(', ', $raw);

        // Las listas personalizadas guardan el valor: se muestra su etiqueta.
        foreach ($fieldConfig['options'] ?? [] as $opt) {
            if ((string) ($opt['value'] ?? '') === (string) $raw) return $this->clean($opt['label'] ?? $raw);
        }

        return $this->clean($raw);
    }

    private function clean($v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return $v === '' ? 'Sin dato' : $v;
    }

    /**
     * Barras "total por X". Agrupa **normalizando** (sin mayúsculas/minúsculas ni
     * espacios de más): en los directorios reales conviven "lobby central nivel 2" y
     * "Lobby Central Nivel 2", y contarlos aparte parte la barra en dos. La etiqueta
     * que se muestra es la escritura más frecuente, en Título si venía toda en altas
     * o toda en bajas.
     */
    private function groupRows(Collection $rows, string $fieldKey, array $fieldConfig): array
    {
        return $rows
            ->map(fn ($r) => $this->valueOf($r['device'], $fieldKey, $fieldConfig))
            ->groupBy(fn (string $v) => $this->normalize($v))
            ->map(fn (Collection $variants) => [
                'label' => $this->displayLabel($variants),
                'count' => $variants->count(),
            ])
            ->sortByDesc('count')
            ->take(self::ROWS)
            ->values()->all();
    }

    private function normalize(string $v): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)), 'UTF-8');
    }

    /** Escritura más frecuente del grupo; en Título si venía toda en altas o bajas. */
    private function displayLabel(Collection $variants): string
    {
        $label = (string) $variants->countBy()->sortDesc()->keys()->first();

        $isUpper = $label === mb_strtoupper($label, 'UTF-8');
        $isLower = $label === mb_strtolower($label, 'UTF-8');

        return ($isUpper || $isLower) ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8') : $label;
    }

    /** Serie por día, en el orden del periodo (como el eje año–mes–día del tablero). */
    private function timeline(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($r) => $r['date']->toDateString())
            ->map(fn ($g, $day) => [
                'date'  => $day,
                'label' => Carbon::parse($day)->format('d'),
                'month' => Carbon::parse($day)->locale('es')->isoFormat('MMM YY'),
                'count' => $g->count(),
            ])
            ->sortBy('date')
            ->values()->all();
    }

    // ── Construcción ──────────────────────────────────────────────────────────

    public function build(): array
    {
        $config  = $this->config ?: $this->defaultConfig();
        $fields  = $this->groupableFields()->keyBy('field_key');
        $devices = $this->devices();

        // Todos los servicios del periodo, para los acumulados generales.
        $allRows = collect();
        $serviceTypes = [];

        foreach ($config['sections'] ?? [] as $section) {
            if (! ($section['enabled'] ?? true)) continue;
            $rows = $this->rowsFor($section['key']);
            $allRows = $allRows->concat($rows);
            $serviceTypes[] = ['label' => $this->labelFor($section['key']), 'count' => $rows->count()];
        }

        $summary = null;
        if ($config['summary']['enabled'] ?? true) {
            $groups = [];
            foreach ($config['summary']['group_by'] ?? [] as $fieldKey) {
                $def = $fields[$fieldKey] ?? null;
                if (! $def) continue;
                $groups[] = [
                    'label' => $def['label'],
                    'rows'  => $this->groupRows($allRows, $fieldKey, $def['config']),
                ];
            }
            if ($config['summary']['by_device_type'] ?? true) {
                $groups[] = [
                    'label' => 'Tipo de dispositivo',
                    'rows'  => $this->groupRows($allRows, self::DEVICE_TYPE, []),
                ];
            }

            $summary = [
                'total_devices'  => $devices->count(),
                'total_services' => $allRows->count(),
                'service_types'  => collect($serviceTypes)->sortByDesc('count')->values()->all(),
                'groups'         => $groups,
            ];
        }

        $sections = [];
        foreach ($config['sections'] ?? [] as $section) {
            if (! ($section['enabled'] ?? true)) continue;

            $rows  = $this->rowsFor($section['key']);
            $label = $this->labelFor($section['key']);

            // % de avance = dispositivos DISTINTOS atendidos sobre el total del sitio.
            $covered = $rows->pluck('device')->filter()->pluck('id')->unique()->count();
            $total   = max(1, $devices->count());

            $groups = [];
            foreach ($section['group_by'] ?? [] as $fieldKey) {
                $def = $fields[$fieldKey] ?? null;
                if (! $def) continue;
                $groups[] = ['label' => $def['label'], 'rows' => $this->groupRows($rows, $fieldKey, $def['config'])];
            }

            $sections[] = [
                'key'             => $section['key'],
                'label'           => $label,
                'title'           => $section['title'] ?? ('Servicios de ' . mb_strtolower($label) . ' en el periodo'),
                'count'           => $rows->count(),
                'devices_covered' => $covered,
                'progress_pct'    => ($section['progress'] ?? true) ? round($covered / $total * 100, 2) : null,
                'by_device_type'  => ($section['by_device_type'] ?? true) ? $this->groupRows($rows, self::DEVICE_TYPE, []) : [],
                'groups'          => $groups,
                'timeline'        => ($section['timeline'] ?? true) ? $this->timeline($rows) : [],
            ];
        }

        return [
            'meta' => [
                'site'         => $this->site->name,
                'client'       => $this->site->client?->name,
                'system'       => $this->systemId ? Catalog::where('id', $this->systemId)->value('label') : null,
                'from'         => $this->from->toDateString(),
                'to'           => $this->to->toDateString(),
                'period_label' => $this->periodLabel(),
                'title'        => $config['title'] ?? 'Resumen de servicios del sitio',
                'subtitle'     => $config['subtitle'] ?? null,
                'generated_at' => now()->toDateTimeString(),
            ],
            'summary'  => $summary,
            'sections' => $sections,
        ];
    }

    private function periodLabel(): string
    {
        $a = $this->from->locale('es')->isoFormat('MMMM YYYY');
        $b = $this->to->locale('es')->isoFormat('MMMM YYYY');
        $label = $a === $b ? $a : "$a - $b";

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }
}
