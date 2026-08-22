<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\MaintenanceActivityFilters;
use App\Models\ActivityTypeField;
use App\Models\SystemField;
use App\Models\AppSetting;
use App\Models\Catalog;
use App\Models\Device;
use App\Models\Directory;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventStatusHistory;
use App\Models\EventType;
use App\Models\EventTypeField;
use App\Models\FloorPlan;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Services\Imports\AdistImportService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\EventFolio;
use App\Support\WebhookEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceActivityController extends Controller
{
    use MaintenanceActivityFilters;

    /**
     * Resuelve la fecha de ejecución respetando el flag global. Si la captura manual
     * está apagada (o no viene fecha), usa la del momento ($fallback). Si está prendida
     * y viene una fecha válida no futura, la respeta (fecha de ejecución de la migración).
     */
    private function resolveExecutionDate(?string $provided, $fallback)
    {
        // El flag global habilita la fecha para todos; además, quien tenga el permiso
        // `activities.set-execution-date` (ingenieros por defecto; superadmin por bypass)
        // puede fijarla/corregirla aunque el flag esté apagado. Tope = hoy (sin futuro).
        $allowed = AppSetting::executionDateAllowed()
            || (bool) optional(request()->user())->can('activities.set-execution-date');
        if (! $allowed || empty($provided)) {
            return $fallback;
        }
        $d = Carbon::parse($provided);
        return $d->startOfDay()->lte(Carbon::today()) ? $d : $fallback;
    }

    private function authorizeAccess(Maintenance $maintenance): void
    {
        $user = request()->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-sitio') &&
            $user->sitesAsAdmin()->where('sites.id', $maintenance->site_id)->exists()
        ) return;

        if ($user->hasRole('admin-cliente')) {
            $maintenance->loadMissing('site');
            if ($user->clientsAsAdmin()->where('clients.id', $maintenance->site->client_id)->exists()) return;
        }

        if ($user->hasRole('ingeniero') &&
            $maintenance->engineers()->where('users.id', $user->id)->exists()
        ) return;

        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    /** GET /maintenances/{maintenance}/activity-types
     *  Tipos de actividad asociados al sistema del mantenimiento
     */
    public function activityTypes(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $types = Catalog::ofType(Catalog::TYPE_ACTIVITY_TYPE)
            ->whereExists(fn ($q) => $q
                ->from('activity_type_systems')
                ->whereColumn('activity_type_id', 'catalogs.id')
                ->where('system_id', $maintenance->catalog_id)
            )
            ->get(['id', 'label']);

        return response()->json($types);
    }

    /** GET /maintenances/{maintenance}/activity-devices
     *  Directorios + dispositivos del sistema (sin conteos — carga rápida)
     */
    public function devices(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $systemId = $maintenance->catalog_id;

        $directories = Directory::with([
            'devices' => fn ($q) => $q->where('is_active', true)->whereNull('archived_at')->orderBy('name'),
        ])
            ->where('site_id', $maintenance->site_id)
            ->where('catalog_id', $systemId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $result = $directories->map(function ($dir) {
            $devices = $dir->devices->map(fn ($dev) => [
                'id'            => $dev->id,
                'name'          => $dev->name,
                'device_type'   => $dev->device_type,
                'custom_fields' => $dev->custom_fields ?? [],
            ])->values();

            return ['id' => $dir->id, 'name' => $dir->display_name, 'devices' => $devices];
        })->filter(fn ($d) => count($d['devices']) > 0)->values();

        return response()->json(['directories' => $result]);
    }

    /** GET /maintenances/{maintenance}/activity-counts
     *  Conteo de actividades por (device_id → activity_type_id) — carga lazy
     */
    public function activityCounts(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $rows = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->select('device_id', 'activity_type_id', DB::raw('count(*) as cnt'))
            ->groupBy('device_id', 'activity_type_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->device_id][$row->activity_type_id] = (int) $row->cnt;
        }

        return response()->json($result);
    }

    /** GET /maintenances/{maintenance}/floor-plans
     *  Planos activos del sitio + sembrado de los dispositivos de ESTE sistema
     *  (solo lectura). El frontend cruza con activity-counts para colorear.
     */
    public function floorPlans(Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        // dispositivos del directorio (sistema) de este mantenimiento
        $deviceIds = Device::whereHas('directory', fn ($q) => $q
            ->where('site_id', $maintenance->site_id)
            ->where('catalog_id', $maintenance->catalog_id)
        )->whereNull('archived_at')->pluck('id');

        $plans = FloorPlan::where('site_id', $maintenance->site_id)
            ->where('is_active', true)
            ->with(['placements' => fn ($q) => $q
                ->whereIn('device_id', $deviceIds)
                ->with('device:id,name,device_type,status,custom_fields'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FloorPlan $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'image_url'    => $p->image_url,
                'image_width'  => $p->image_width,
                'image_height' => $p->image_height,
                'placements'   => $p->placements->map(fn ($pl) => [
                    'device_id'    => $pl->device_id,
                    'x'            => (float) $pl->x,
                    'y'            => (float) $pl->y,
                    'name'         => $pl->device?->name,
                    'device_type'  => $pl->device?->device_type,
                    'status'       => $pl->device?->status,
                    'custom_fields'=> $pl->device?->custom_fields,
                ])->values(),
            ]);

        return response()->json($plans);
    }

    /** POST /maintenances/{maintenance}/activities */
    public function store(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para registrar actividades.');

        $data = $request->validate([
            'device_id'        => 'required|integer|exists:devices,id',
            'activity_type_id' => 'required|integer|exists:catalogs,id',
            'field_values'     => 'nullable|array',
            'performed_at'     => 'nullable|date',
        ]);

        // Validar que el dispositivo pertenece al sistema del mantenimiento
        $device = Device::findOrFail($data['device_id']);
        $dir    = Directory::findOrFail($device->directory_id);
        abort_unless(
            $dir->site_id === $maintenance->site_id && $dir->catalog_id === $maintenance->catalog_id,
            422,
            'El dispositivo no pertenece al sistema de este mantenimiento.'
        );

        // Validar que el tipo de actividad está asociado al sistema
        $isLinked = DB::table('activity_type_systems')
            ->where('activity_type_id', $data['activity_type_id'])
            ->where('system_id', $maintenance->catalog_id)
            ->exists();
        abort_unless($isLinked, 422, 'El tipo de actividad no aplica para este sistema.');

        $activity = MaintenanceActivity::create([
            'maintenance_id'   => $maintenance->id,
            'device_id'        => $data['device_id'],
            'activity_type_id' => $data['activity_type_id'],
            'user_id'          => $request->user()->id,
            'field_values'     => $data['field_values'] ?? [],
            'performed_at'     => $this->resolveExecutionDate($data['performed_at'] ?? null, now()),
        ]);

        // Webhook saliente al cliente/sitio del mantenimiento.
        $maintenance->loadMissing('site:id,client_id');
        app(WebhookDispatcher::class)->dispatch(
            WebhookEvent::ACTIVITY_DOCUMENTED,
            $maintenance->site?->client_id,
            $maintenance->site_id,
            WebhookEvent::activityData($activity, $request->user()),
        );

        return response()->json(
            $activity->load(['activityType:id,label', 'user:id,name']),
            201
        );
    }

    /** GET /maintenances/{maintenance}/devices/{device}/activities
     *  Historial de actividades de un dispositivo en este mantenimiento
     */
    public function deviceActivities(Maintenance $maintenance, Device $device): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        $activities = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->where('device_id', $device->id)
            ->with(['activityType:id,label', 'user:id,name'])
            ->orderByDesc('performed_at')
            ->get();

        return response()->json($activities);
    }

    /** GET /maintenances/{maintenance}/log?date_from=Y-m-d&date_to=Y-m-d
     *  Todas las actividades del mantenimiento en el rango de fechas (por performed_at)
     */
    /**
     * GET /maintenances/{maintenance}/log-pdf — la bitácora del mantenimiento en PDF,
     * con la misma consulta y filtros que la pestaña.
     */
    /**
     * GET /maintenances/{maintenance}/log-pdf — la bitácora como DOCUMENTO.
     *
     * Misma ficha que la bitácora de eventos (la dibuja el mismo `LogPdf`): antes era una
     * tabla de seis columnas donde el detalle capturado se resumía a 300 caracteres en una
     * celda, perdiendo justo lo que interesa revisar.
     */
    public function logPdf(Request $request, Maintenance $maintenance): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $payload = $this->log($request, $maintenance)->getData(true);
        $entries = $payload['entries'] ?? [];

        $maintenance->loadMissing(['site:id,name,client_id', 'site.client:id,name', 'system:id,label']);
        $systemId = (int) $maintenance->catalog_id;

        // Campos del DIRECTORIO marcados para bitácora (los de la tarjeta de dispositivo).
        // OJO: la columna es `catalog_id` (un sistema es un catálogo), y sólo la
        // plantilla BASE —`client_id` nulo—, que es exactamente lo que pide la pantalla.
        $deviceFields = SystemField::where('catalog_id', $systemId)
            ->whereNull('client_id')
            ->where('is_active', true)
            ->where('show_in_bitacora', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // Campos del FORMULARIO marcados para bitácora, por tipo de actividad presente.
        $typeIds = collect($entries)->pluck('activity_type.id')->filter()->unique();

        $formFields = ActivityTypeField::whereIn('activity_type_id', $typeIds)
            ->where('system_id', $systemId)
            ->where('is_active', true)
            ->where('show_in_bitacora', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('activity_type_id');

        $didKey = $this->didKeyForSystem($systemId, $maintenance->site?->client_id);

        // Sólo quien puede ver la fecha de registro la recibe impresa: el PDF respeta el
        // mismo permiso que la pantalla.
        $showCreated = $request->user()?->can('activities.view-registration-date') ?? false;

        $days = [];

        foreach ($entries as $e) {
            $when = Carbon::parse($e['performed_at'] ?? now());
            $key  = $when->toDateString();

            $days[$key] ??= [
                'label'   => $when->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
                'entries' => [],
            ];

            $days[$key]['entries'][] = $this->logCard($e, $deviceFields, $formFields, $didKey, $when, $showCreated);
        }

        ksort($days);

        $binary = (new \App\Services\Pdf\LogPdf([
            'meta' => [
                'title'        => 'Bitácora del mantenimiento',
                'client'       => $maintenance->site?->client?->name,
                'site'         => $maintenance->site?->name,
                'system'       => $maintenance->system?->label,
                'period_label' => $request->filled('date_from') || $request->filled('date_to')
                    ? trim((string) $request->input('date_from')) . ' - ' . trim((string) $request->input('date_to'))
                    : 'Todo el periodo',
                'generated_at' => now()->toDateTimeString(),
            ],
            'summary' => ['count' => count($entries), 'noun' => 'captura'],
            'days'    => array_values($days),
        ]))
            ->withSignature($request->input('signature'), $request->input('signature_align'))
            ->withBranding(\App\Support\Tenant::fromRequest($request))
            ->render();

        $name = \App\Support\PrintableName::build(
            'Bitacora Mantenimiento',
            trim(($maintenance->site?->name ?? '') . ' ' . ($maintenance->system?->label ?? '')),
            $request->input('date_from'),
            $request->input('date_to'),
        );

        return response()->streamDownload(fn () => print($binary), $name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** Una captura, ya formateada para imprimir. */
    private function logCard(array $e, $deviceFields, $formFields, string $didKey, Carbon $when, bool $showCreated): array
    {
        $device = $e['device'] ?? null;
        $cf     = is_array($device['custom_fields'] ?? null) ? $device['custom_fields'] : [];
        $did    = $cf[$didKey] ?? null;

        $values = is_array($e['field_values'] ?? null) ? $e['field_values'] : [];

        // Datos del directorio marcados para bitácora: van como pares, que aprietan más
        // que un bloque por campo y son valores cortos.
        $pairs = [
            ['Fecha',      $when->format('d/m/Y')],
            ['Capturó',    $e['user']['name'] ?? null],
        ];

        if ($showCreated && ! empty($e['created_at'])) {
            $pairs[] = ['Registro', Carbon::parse($e['created_at'])->format('d/m/Y H:i')];
        }

        foreach ($deviceFields as $def) {
            // El DID ya va como distintivo de la ficha y dentro de la línea del dispositivo:
            // repetirlo como par sería la tercera vez en la misma tarjeta.
            if ($def->field_key === $didKey) continue;

            $text = \App\Support\FieldValueText::format($cf[$def->field_key] ?? null, (string) $def->field_type, is_array($def->config) ? $def->config : []);
            if (trim($text) !== '') $pairs[] = [$def->label, $text];
        }

        $fields = [];
        foreach ($formFields[$e['activity_type']['id'] ?? null] ?? [] as $def) {
            $fields[] = \App\Support\FieldValueText::field([
                'label'       => $def->label,
                'field_type'  => $def->field_type,
                'legend_text' => $def->legend_text,
                'config'      => $def->config,
            ], $values[$def->field_key] ?? null);
        }

        $deviceLine = trim(implode(' · ', array_filter([
            $device['name'] ?? null,
            $did ? 'DID ' . $did : null,
            $device['device_type'] ?? null,
        ])));

        return [
            'title'  => (string) ($e['activity_type']['label'] ?? 'Actividad'),
            // El DID identifica al dispositivo como el folio identifica al evento.
            'badge'  => $did ? (string) $did : '',
            'pairs'  => $pairs,
            'wide'   => [
                ['Dispositivo', $deviceLine],
                ['Directorio',  $device['directory_name'] ?? ''],
            ],
            'fields' => $fields,
            'images_label' => 'Imágenes de la captura',
        ];
    }

    public function log(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeAccess($maintenance);

        ['activities' => $activities, 'available' => $available] = $this->gatherActivities($request, $maintenance);

        $result = $activities->map(fn ($act) => [
            'id'            => $act->id,
            'performed_at'  => $act->performed_at,
            'created_at'    => $act->created_at,
            'field_values'  => $act->field_values ?? [],
            'activity_type' => $act->activityType,
            'user'          => $act->user,
            'device'        => $act->device ? [
                'id'             => $act->device->id,
                'name'           => $act->device->name,
                'device_type'    => $act->device->device_type,
                'custom_fields'  => $act->device->custom_fields ?? [],
                'directory_name' => $act->device->directory?->name,
            ] : null,
        ]);

        return response()->json([
            'entries'           => $result,
            'available_filters' => $available,
        ]);
    }

    /**
     * Reúne las actividades del mantenimiento aplicando el mismo criterio que el dashboard:
     * fecha (performed_at) + filtros de directorio y de formulario. Reutilizado por el log
     * (JSON) y por la exportación a Excel.
     *
     * @return array{activities: \Illuminate\Support\Collection, available: array, systemId: int}
     */
    private function gatherActivities(Request $request, Maintenance $maintenance): array
    {
        $validated = $request->validate([
            'date_from'    => 'nullable|date',
            'date_to'      => 'nullable|date|after_or_equal:date_from',
            'dir_filters'  => 'nullable',
            'form_filters' => 'nullable',
        ]);

        $systemId = $maintenance->catalog_id;
        $siteId   = $maintenance->site_id;
        $filters  = $this->parseMaintenanceFilters($request);

        // ── Universo base (para metadatos de filtros disponibles) ─────────────
        $baseDeviceQuery = Device::whereHas('directory', fn ($q) =>
            $q->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
        )->where('is_active', true)->whereNull('archived_at');
        $baseDevices = (clone $baseDeviceQuery)->get(['id', 'custom_fields']);

        $baseActivities = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->select('id', 'device_id', 'activity_type_id', 'field_values')
            ->whereIn('device_id', $baseDevices->pluck('id'))
            ->get();

        $linkedTypeIds = DB::table('activity_type_systems')->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $linkedTypeIds)
            ->where('type', Catalog::TYPE_ACTIVITY_TYPE)->where('is_active', true)
            ->get(['id', 'label']);

        $filterMeta = $this->maintenanceFilterMeta($systemId, $baseDevices, $baseActivities, $activityTypes);

        // ── Dispositivos en scope (filtros de directorio) ─────────────────────
        $this->applyDirectoryFilters($baseDeviceQuery, $filters['dir'], $filterMeta['dir_modes']);
        $scopedDeviceIds = $baseDeviceQuery->pluck('id');

        // ── Actividades: fecha + dispositivo en scope ─────────────────────────
        $query = MaintenanceActivity::where('maintenance_id', $maintenance->id)
            ->whereIn('device_id', $scopedDeviceIds);
        if (!empty($validated['date_from'])) $query->whereDate('performed_at', '>=', $validated['date_from']);
        if (!empty($validated['date_to']))   $query->whereDate('performed_at', '<=', $validated['date_to']);

        $activities = $query->with([
                'activityType:id,label',
                'user:id,name',
                'device:id,name,device_type,custom_fields,directory_id',
                'device.directory:id,name',
            ])
            ->orderBy('performed_at')
            ->orderBy('id')
            ->get()
            // Filtros de formulario (por tipo) — en memoria sobre field_values
            ->filter(fn ($a) => $this->activityPassesFormFilters($a, $filters['form'], $filterMeta['form_modes']))
            ->values();

        return ['activities' => $activities, 'available' => $filterMeta['available'], 'systemId' => $systemId];
    }

    /**
     * GET /maintenances/{maintenance}/activities/{activity}
     * Detalle de UNA actividad (para su pantalla propia): dispositivo + directorio, tipo,
     * usuario, fecha, valores capturados y origen (ADIST). Los campos del formulario los
     * resuelve el front con /activity-types/{type}/systems/{system}/fields.
     */
    public function show(Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        $this->authorizeAccess($maintenance);
        abort_unless((int) $activity->maintenance_id === (int) $maintenance->id, 404, 'La actividad no pertenece a este mantenimiento.');

        $activity->load([
            'activityType:id,label',
            'user:id,name',
            'device:id,name,device_type,custom_fields,directory_id',
            'device.directory:id,name',
        ]);

        $systemId = (int) $maintenance->catalog_id;
        $maintenance->loadMissing('site');
        $didKey = $this->didKeyForSystem($systemId, $maintenance->site?->client_id);
        $cf = is_array($activity->device?->custom_fields) ? $activity->device->custom_fields : [];
        $fv = is_array($activity->field_values) ? $activity->field_values : [];

        return response()->json([
            'id'            => $activity->id,
            'maintenance_id' => (int) $maintenance->id,
            'system_id'     => $systemId,
            'performed_at'  => $activity->performed_at,
            'created_at'    => $activity->created_at,
            'field_values'  => $fv,
            'source'        => $fv['_origen'] ?? null,
            'activity_type' => $activity->activityType,
            'user'          => $activity->user,
            'device'        => $activity->device ? [
                'id'             => $activity->device->id,
                'name'           => $activity->device->name,
                'device_type'    => $activity->device->device_type,
                'did'            => $cf[$didKey] ?? null,
                'custom_fields'  => $cf,
                'directory_name' => $activity->device->directory?->name,
            ] : null,
        ]);
    }

    /**
     * POST /maintenances/{maintenance}/activities/{activity}/retype
     * Cambia el tipo de UNA actividad. `field_map` = { nuevaLlave: viejaLlave }: cada campo
     * del nuevo tipo toma el valor del campo viejo elegido. Los valores no mapeados se
     * CONSERVAN OCULTOS (quedan en el registro; los que chocan con una llave del nuevo tipo
     * se mueven a `_orphans` para no filtrarse como valor del nuevo campo).
     */
    public function retype(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        $this->authorizeAccess($maintenance);
        abort_unless((int) $activity->maintenance_id === (int) $maintenance->id, 404, 'La actividad no pertenece a este mantenimiento.');

        $data = $request->validate([
            'activity_type_id' => 'required|integer|exists:catalogs,id',
            'field_map'        => 'nullable|array',   // { nuevaLlave: viejaLlave }
            'field_map.*'      => 'nullable|string',
        ]);

        $systemId = (int) $maintenance->catalog_id;
        $newTypeId = (int) $data['activity_type_id'];

        // El tipo destino debe estar vinculado a este sistema.
        $linked = DB::table('activity_type_systems')
            ->where('system_id', $systemId)->where('activity_type_id', $newTypeId)->exists();
        abort_unless($linked, 422, 'El tipo de actividad destino no aplica a este sistema.');

        $newKeys = \App\Models\ActivityTypeField::where('activity_type_id', $newTypeId)
            ->where('system_id', $systemId)->where('is_active', true)
            ->pluck('field_key')->all();

        $old = is_array($activity->field_values) ? $activity->field_values : [];
        $map = is_array($data['field_map'] ?? null) ? $data['field_map'] : [];

        // Parte del viejo (conserva todo lo capturado como oculto).
        $fv = $old;
        $orphans = is_array($old['_orphans'] ?? null) ? $old['_orphans'] : [];

        foreach ($newKeys as $nk) {
            $srcKey = $map[$nk] ?? null;
            if ($srcKey && array_key_exists($srcKey, $old)) {
                $fv[$nk] = $old[$srcKey];
            } else {
                // Sin mapeo: limpia la llave del nuevo tipo para no filtrar un valor viejo
                // homónimo; si tenía algo, se guarda como huérfano recuperable.
                if (array_key_exists($nk, $old) && ($old[$nk] ?? null) !== null && $old[$nk] !== '') {
                    $orphans[$nk] = $old[$nk];
                }
                unset($fv[$nk]);
            }
        }
        if ($orphans) {
            $fv['_orphans'] = $orphans;
        }

        $activity->update(['activity_type_id' => $newTypeId, 'field_values' => $fv]);

        return response()->json([
            'message'  => 'Actividad retipificada.',
            'activity' => $activity->fresh(['activityType:id,label']),
        ]);
    }

    // ─── Transferir una actividad a un EVENTO (superadmin) ────────────────────
    // Se documentó como actividad algo que era un evento: el superadmin crea un evento
    // nuevo del tipo elegido, mapea los campos capturados y elimina la actividad.

    /**
     * GET /maintenances/{maintenance}/activities/{activity}/transfer-options
     * Insumos del asistente: tipos de evento del sistema del mantenimiento + los campos
     * de la actividad (con vista previa de su valor) como origen del mapeo.
     */
    public function transferOptions(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403, 'Solo un superadministrador puede transferir a evento.');
        abort_unless($activity->maintenance_id === $maintenance->id, 404);

        $systemId = (int) $maintenance->catalog_id;

        $eventTypes = EventType::where('is_active', true)
            ->whereHas('linkedSystems', fn ($q) => $q->where('catalogs.id', $systemId))
            ->orderBy('label')->get(['id', 'label', 'nature']);

        $fields = ActivityTypeField::where('activity_type_id', $activity->activity_type_id)
            ->where('system_id', $systemId)->where('is_active', true)
            ->where('field_type', '!=', 'leyenda')
            ->orderBy('sort_order')->orderBy('id')->get();

        $fv = is_array($activity->field_values) ? $activity->field_values : [];
        $sourceFields = $fields->map(fn ($f) => [
            'field_key'  => $f->field_key,
            'label'      => $f->label,
            'field_type' => $f->field_type,
            'preview'    => $this->formatCellValue($fv[$f->field_key] ?? null, $f),
        ])->values();

        // Con `event_type_id` devuelve además los campos DESTINO (activos) del evento.
        $destFields = [];
        if ($request->filled('event_type_id')) {
            $destModels = EventTypeField::where('event_type_id', (int) $request->event_type_id)
                ->where('system_id', $systemId)->where('is_active', true)
                ->where('field_type', '!=', 'leyenda')
                ->orderBy('sort_order')->orderBy('id')->get();
            $destFields = collect($destModels)->map(fn ($f) => [
                'field_key'  => $f->field_key,
                'label'      => $f->label,
                'field_type' => $f->field_type,
            ])->values();
        }

        return response()->json([
            'system_id'     => $systemId,
            'event_types'   => $eventTypes,
            'source_fields' => $sourceFields,
            'dest_fields'   => $destFields,
        ]);
    }

    /**
     * POST /maintenances/{maintenance}/activities/{activity}/transfer-to-event
     * Crea un evento del tipo elegido con los campos mapeados (arrastra sitio/sistema/
     * dispositivo/fecha de la actividad) y ELIMINA la actividad. Superadmin.
     */
    public function transferToEvent(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403, 'Solo un superadministrador puede transferir a evento.');
        abort_unless($activity->maintenance_id === $maintenance->id, 404);

        $data = $request->validate([
            'event_type_id' => 'required|integer|exists:event_types,id',
            'field_map'     => 'array',           // { destKey: sourceKey }
            'field_map.*'   => 'nullable|string',
            'description'   => 'nullable|string',
        ]);

        $maintenance->loadMissing('site');
        $site = $maintenance->site;
        abort_if(! $site, 422, 'El mantenimiento no tiene sitio.');
        $systemId = (int) $maintenance->catalog_id;

        // El tipo de evento debe estar ligado al sistema del mantenimiento.
        $type = EventType::where('id', $data['event_type_id'])
            ->whereHas('linkedSystems', fn ($q) => $q->where('catalogs.id', $systemId))
            ->first();
        abort_if(! $type, 422, 'El tipo de evento no pertenece al sistema del mantenimiento.');

        // Campos destino (evento) + su descripción para coerción.
        $destFieldModels = EventTypeField::where('event_type_id', $type->id)
            ->where('system_id', $systemId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $svc       = app(AdistImportService::class);
        $destMetas = $svc->describeDestFields($destFieldModels);

        // Tipo de cada campo ORIGEN (actividad) para copiar directo cuando coincide.
        $srcTypeByKey = ActivityTypeField::where('activity_type_id', $activity->activity_type_id)
            ->where('system_id', $systemId)
            ->pluck('field_type', 'field_key')->all();

        $fieldValues = $this->mapActivityValuesToEvent(
            $data['field_map'] ?? [],
            $destMetas,
            is_array($activity->field_values) ? $activity->field_values : [],
            $srcTypeByKey,
            $svc,
        );

        $status = EventStatus::where('is_initial', true)->orderBy('sort_order')->first()
            ?? EventStatus::orderBy('sort_order')->first();
        abort_if(! $status, 422, 'No hay estados de evento configurados.');

        $description = trim((string) ($data['description'] ?? ''))
            ?: ('Transferido desde actividad de mantenimiento #' . $maintenance->id
                . ' (' . (optional($activity->activityType)->label ?? 'actividad') . ').');

        $user = $request->user();

        $event = DB::transaction(function () use ($site, $systemId, $type, $activity, $status, $fieldValues, $description, $user) {
            $event = Event::create([
                'folio'         => EventFolio::next($site->client),
                'client_id'     => $site->client_id,
                'site_id'       => $site->id,
                'system_id'     => $systemId,
                'event_type_id' => $type->id,
                'device_id'     => $activity->device_id,
                'status_id'     => $status->id,
                'priority'      => $type->default_priority ?: 'media',
                'priority_auto' => false,
                'description'   => $description,
                'field_values'  => ! empty($fieldValues) ? $fieldValues : null,
                'created_by'    => $user->id,
                'occurred_at'   => $activity->performed_at,
            ]);

            EventStatusHistory::create([
                'event_id'       => $event->id,
                'from_status_id' => null,
                'to_status_id'   => $status->id,
                'user_id'        => $user->id,
                'note'           => 'Evento creado por transferencia desde una actividad',
                'created_at'     => now(),
            ]);

            // La actividad fue un error de documentación: se elimina tras crear el evento.
            $activity->delete();

            return $event;
        });

        return response()->json([
            'message'  => "Actividad transferida al evento {$event->folio}.",
            'event_id' => $event->id,
            'folio'    => $event->folio,
        ], 201);
    }

    /**
     * Construye los `field_values` del evento a partir del mapa {destKey: sourceKey}.
     * Si el tipo de origen y destino coinciden, copia el valor tal cual (mismo formato
     * canónico, sin pérdida); si difieren, coerciona best-effort con el servicio ADIST.
     */
    private function mapActivityValuesToEvent(array $fieldMap, array $destMetas, array $sourceValues, array $srcTypeByKey, AdistImportService $svc): array
    {
        $destByKey = collect($destMetas)->keyBy('field_key');
        $out = [];
        foreach ($fieldMap as $destKey => $srcKey) {
            if ($srcKey === null || $srcKey === '') continue;
            $meta = $destByKey->get($destKey);
            if (! $meta) continue;
            if (! array_key_exists($srcKey, $sourceValues)) continue;

            $raw = $sourceValues[$srcKey];
            if ($raw === null || $raw === '' || $raw === []) continue;

            $destType = $meta['field_type'];
            $srcType  = $srcTypeByKey[$srcKey] ?? null;

            if ($srcType === $destType) {
                $out[$destKey] = $raw;                       // mismo tipo → copia directa
                continue;
            }
            // Tipos distintos → string best-effort + coerción al tipo destino.
            $asString = is_array($raw)
                ? implode(', ', array_map('strval', $raw))
                : (is_bool($raw) ? ($raw ? 'Sí' : 'No') : (string) $raw);
            $coerced = $svc->coerceValue($meta, $asString);
            if ($coerced !== null && $coerced !== '' && $coerced !== []) {
                $out[$destKey] = $coerced;
            }
        }
        return $out;
    }

    /**
     * GET /maintenances/{maintenance}/activities/export
     * Descarga en Excel el detalle de las capturas del mantenimiento (una hoja por tipo de
     * actividad; columnas = dispositivo/DID/directorio/fecha/usuario + cada campo del formulario).
     * Respeta el mismo rango de fechas y filtros que el dashboard.
     */
    public function export(Request $request, Maintenance $maintenance): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAccess($maintenance);
        app(\App\Support\ActivityLogger::class)->log('activities', 'exported', "Exportó las capturas del mantenimiento #{$maintenance->id} (Excel)", ['source' => 'request', 'subject_type' => 'Maintenance', 'subject_id' => $maintenance->id]);

        ['activities' => $activities, 'systemId' => $systemId] = $this->gatherActivities($request, $maintenance);

        $maintenance->loadMissing('site');
        $didKey = $this->didKeyForSystem($systemId, $maintenance->site?->client_id);

        // Definiciones de campos por tipo de actividad (activos, en orden; sin leyendas).
        $fieldsByType = [];
        foreach ($activities->pluck('activity_type_id')->unique()->filter() as $tid) {
            $fieldsByType[$tid] = \App\Models\ActivityTypeField::where('activity_type_id', $tid)
                ->where('system_id', $systemId)->where('is_active', true)
                ->where('field_type', '!=', 'leyenda')
                ->orderBy('sort_order')->orderBy('id')->get();
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $usedTitles = [];

        $grouped = $activities->groupBy('activity_type_id');
        if ($grouped->isEmpty()) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Sin capturas');
            $sheet->setCellValue('A1', 'No hay capturas para el rango o filtros seleccionados.');
        }

        foreach ($grouped as $tid => $acts) {
            $typeLabel = optional($acts->first()->activityType)->label ?? ('Tipo '.$tid);
            $fields = $fieldsByType[$tid] ?? collect();

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle($typeLabel, $usedTitles));

            $headers = ['DID', 'Dispositivo', 'Tipo de dispositivo', 'Directorio', 'Fecha', 'Capturado por'];
            foreach ($fields as $f) {
                $headers[] = $f->label;
            }
            $sheet->fromArray($headers, null, 'A1');
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
            $sheet->freezePane('A2');

            $row = 2;
            foreach ($acts as $act) {
                $cf = is_array($act->device?->custom_fields) ? $act->device->custom_fields : [];
                $fv = is_array($act->field_values) ? $act->field_values : [];
                $line = [
                    (string) ($cf[$didKey] ?? ''),
                    $act->device?->name ?? '',
                    $act->device?->device_type ?? '',
                    $act->device?->directory?->name ?? '',
                    optional($act->performed_at)->format('d/m/Y'),
                    $act->user?->name ?? '',
                ];
                foreach ($fields as $f) {
                    $line[] = $this->formatCellValue($fv[$f->field_key] ?? null, $f);
                }
                $sheet->fromArray($line, null, 'A'.$row);
                $row++;
            }

            for ($ci = 1; $ci <= count($headers); $ci++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $filename = 'capturas-mantenimiento-'.$maintenance->id.'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Valor legible de un campo del formulario para una celda de Excel. */
    private function formatCellValue($v, \App\Models\ActivityTypeField $field): string
    {
        $type = $field->field_type;
        if ($v === null || $v === '' || $v === []) {
            return '';
        }
        if (in_array($type, ['custom_list', 'custom_multiselect'], true)) {
            $opts = is_array($field->config) ? ($field->config['options'] ?? []) : [];
            $map = [];
            foreach ($opts as $o) {
                if (isset($o['value'])) {
                    $map[(string) $o['value']] = $o['label'] ?? $o['value'];
                }
            }
            $vals = is_array($v) ? $v : [$v];

            return implode(', ', array_map(fn ($x) => $map[(string) $x] ?? (string) $x, $vals));
        }
        if (is_bool($v)) {
            return $v ? 'Sí' : 'No';
        }
        if (in_array($type, ['image', 'signature'], true)) {
            $imgs = array_filter(is_array($v) ? $v : [$v], fn ($x) => is_string($x) && $x !== '');

            return implode(' | ', $imgs);
        }
        if (is_array($v)) {
            return implode(', ', array_map('strval', $v));
        }

        return (string) $v;
    }

    /** Clave del campo DID del sistema (field_type='did', override por cliente); fallback 'did'. */
    private function didKeyForSystem(int $systemId, ?int $clientId): string
    {
        $did = \App\Models\SystemField::where('catalog_id', $systemId)
            ->where('field_type', 'did')
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)))
            ->orderByRaw('client_id is null')
            ->value('field_key');

        return $did ?: 'did';
    }

    /** Título de hoja válido (≤31, sin caracteres prohibidos) y único dentro del libro. */
    private function uniqueSheetTitle(string $title, array &$used): string
    {
        $t = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title));
        $t = mb_substr($t, 0, 28) ?: 'Hoja';
        $base = $t;
        $i = 2;
        while (isset($used[mb_strtolower($t)])) {
            $t = mb_substr($base, 0, 25).' '.$i;
            $i++;
        }
        $used[mb_strtolower($t)] = true;

        return $t;
    }

    /** PUT /maintenances/{maintenance}/activities/{activity} */
    public function update(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($activity->maintenance_id === $maintenance->id, 404);
        $this->authorizeAccess($maintenance);
        abort_unless($request->user()->can('maintenances.record-activity'), 403, 'Sin permiso para editar actividades.');

        $data = $request->validate([
            'field_values' => 'nullable|array',
            'performed_at' => 'nullable|date',
        ]);

        $activity->update([
            'field_values' => $data['field_values'] ?? $activity->field_values,
            'performed_at' => $this->resolveExecutionDate($data['performed_at'] ?? null, $activity->performed_at),
        ]);

        return response()->json(
            $activity->fresh()->load(['activityType:id,label', 'user:id,name'])
        );
    }

    /** DELETE /maintenances/{maintenance}/activities/{activity} */
    public function destroy(Request $request, Maintenance $maintenance, MaintenanceActivity $activity): JsonResponse
    {
        abort_unless($activity->maintenance_id === $maintenance->id, 404);
        $this->authorizeAccess($maintenance);   // faltaba: sin esto se borraban actividades de mantenimientos ajenos
        abort_unless(
            $request->user()->can('maintenances.record-activity') || $request->user()->id === $activity->user_id,
            403,
            'Sin permiso para eliminar esta actividad.'
        );

        $activity->delete();

        return response()->json(['message' => 'Actividad eliminada.']);
    }
}
