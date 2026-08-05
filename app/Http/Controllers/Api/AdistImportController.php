<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAdistImages;
use App\Models\Catalog;
use App\Models\EventStatus;
use App\Models\EventType;
use App\Models\Maintenance;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\AdistImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistente de importación desde ADIST3 (sistema anterior, conexión `pruebas`) hacia un
 * mantenimiento nuestro. Superadmin-only (permiso integrations.import-external).
 *
 * Flujo: options (elegir destino) → preview (analizar, sin escribir) → commit (crear las
 * actividades; las imágenes se bajan en segundo plano con notificación).
 *
 * A prueba de fallos: si la base externa no responde, devuelve un mensaje claro sin tumbar
 * la plataforma (mismo espíritu que las integraciones).
 */
class AdistImportController extends Controller
{
    public function __construct(private AdistImportService $service)
    {
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()->can('integrations.import-external'), 403, 'No autorizado para esta acción.');
    }

    /** Datos para armar el asistente: mantenimientos destino + usuarios para atribuir. */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        // Solo mantenimientos NO cancelados, no archivados, de sitios activos con cliente no archivado.
        $maintenances = Maintenance::visible()
            ->where('status', '!=', 'cancelado')
            ->whereHas('site', fn ($q) => $q->where('is_active', true)->whereHas('client'))
            ->with(['site.client', 'system'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Maintenance $m) => [
                'id'     => $m->id,
                'label'  => trim(
                    (optional(optional($m->site)->client)->name ?: 'Cliente')
                    .' · '.(optional($m->site)->name ?: 'Sitio')
                    .' · '.(optional($m->system)->label ?: 'Sistema')
                    .' (#'.$m->id.')'
                ),
                'client' => optional(optional($m->site)->client)->name,
                'site'   => optional($m->site)->name,
                'system' => optional($m->system)->label,
            ]);

        $users = User::query()
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        // Destino ACTIVIDAD: tipos de actividad para elegir el destino explícito.
        $activityTypes = Catalog::where('type', Catalog::TYPE_ACTIVITY_TYPE)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label]);

        // Destino EVENTO: sitios ACTIVOS de clientes NO archivados (whereHas excluye soft-deleted).
        $sites = Site::where('is_active', true)->whereHas('client')->with('client:id,name')->orderBy('name')
            ->get(['id', 'name', 'client_id'])
            ->map(fn (Site $s) => [
                'id'        => $s->id,
                'client_id' => $s->client_id,
                'label'     => trim((optional($s->client)->name ?: 'Cliente').' · '.$s->name),
            ]);

        $systems = Catalog::where('type', Catalog::TYPE_SYSTEM)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label]);

        $eventTypes = EventType::where('is_active', true)->with('linkedSystems:id')
            ->orderBy('label')->get()
            ->map(fn (EventType $t) => [
                'id'          => $t->id,
                'label'       => $t->label,
                'system_ids'  => $t->linkedSystems->pluck('id')->values(),
            ]);

        $eventStatuses = EventStatus::where('is_active', true)->orderBy('sort_order')
            ->get(['id', 'key', 'label', 'is_initial'])
            ->map(fn (EventStatus $s) => ['key' => $s->key, 'label' => $s->label, 'is_initial' => (bool) $s->is_initial]);

        return response()->json([
            'maintenances'   => $maintenances,
            'users'          => $users,
            'default_type'   => 'PREVENTIVO',
            'activity_types' => $activityTypes,
            'sites'          => $sites,
            'systems'        => $systems,
            'event_types'    => $eventTypes,
            'event_statuses' => $eventStatuses,
        ]);
    }

    /**
     * Campos disponibles para el mapeo origen→destino según el destino elegido:
     * campos de ADIST + campos del formulario destino (con opciones) + mapa por defecto.
     */
    public function fields(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'target'                => ['nullable', 'in:activity,event'],
            'type'                  => ['nullable', 'string', 'max:60'],
            'maintenance_id'        => ['nullable', 'integer', 'exists:maintenances,id'],
            'dest_activity_type_id' => ['nullable', 'integer', 'exists:catalogs,id'],
            'event_type_id'         => ['nullable', 'integer', 'exists:event_types,id'],
            'system_id'             => ['nullable', 'integer', 'exists:catalogs,id'],
        ]);

        $target = $data['target'] ?? 'activity';
        $type = strtoupper($data['type'] ?? 'PREVENTIVO');

        try {
            if ($target === 'event') {
                $request->validate([
                    'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
                    'system_id'     => ['required', 'integer', 'exists:catalogs,id'],
                ]);

                return response()->json($this->service->fieldsPayload(
                    'event', $type, null, null, (int) $data['event_type_id'], (int) $data['system_id'],
                ));
            }

            $request->validate(['maintenance_id' => ['required', 'integer', 'exists:maintenances,id']]);
            $maintenance = Maintenance::findOrFail($data['maintenance_id']);

            return response()->json($this->service->fieldsPayload(
                'activity', $type, $maintenance,
                isset($data['dest_activity_type_id']) ? (int) $data['dest_activity_type_id'] : null,
                null, null,
            ));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudieron cargar los campos del formulario destino.'], 502);
        }
    }

    /** Analiza un IdRequest de ADIST contra un mantenimiento destino (no escribe nada). */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'request_id'        => ['required'],
            'type'              => ['nullable', 'string', 'max:60'],
            'target'            => ['nullable', 'in:activity,event'],
            // Destino ACTIVIDAD
            'maintenance_id'    => ['required_without:target', 'nullable', 'integer', 'exists:maintenances,id'],
            'dest_activity_type_id' => ['nullable', 'integer', 'exists:catalogs,id'],
            // Destino EVENTO
            'site_id'           => ['nullable', 'integer', 'exists:sites,id'],
            'system_id'         => ['nullable', 'integer', 'exists:catalogs,id'],
            'event_type_id'     => ['nullable', 'integer', 'exists:event_types,id'],
        ]);

        $target = $data['target'] ?? 'activity';
        $type = strtoupper($data['type'] ?? 'PREVENTIVO');

        try {
            if ($target === 'event') {
                $request->validate([
                    'site_id'       => ['required', 'integer', 'exists:sites,id'],
                    'system_id'     => ['required', 'integer', 'exists:catalogs,id'],
                    'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
                ]);

                return response()->json($this->service->previewEvents(
                    (int) $data['site_id'], (int) $data['system_id'], (int) $data['event_type_id'],
                    $data['request_id'], $type,
                ));
            }

            $request->validate(['maintenance_id' => ['required', 'integer', 'exists:maintenances,id']]);
            $maintenance = Maintenance::findOrFail($data['maintenance_id']);

            return response()->json($this->service->preview(
                $maintenance, $data['request_id'], $type,
                isset($data['dest_activity_type_id']) ? (int) $data['dest_activity_type_id'] : null,
            ));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo consultar el sistema externo (ADIST). Revisa la conexión e inténtalo de nuevo.',
            ], 502);
        }
    }

    /** Tipos de tarea DISTINTOS de un IdRequest (para el desplegable de origen). */
    public function taskTypes(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate(['request_id' => ['required']]);

        try {
            $types = $this->service->taskTypes($data['request_id']);

            return response()->json([
                'types' => array_map(fn ($t) => ['name' => $t->name, 'count' => (int) $t->n], $types),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudieron consultar los tipos de tarea en ADIST.'], 502);
        }
    }

    /** Detalle completo de una tarea de ADIST (para el drawer). */
    public function task(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'task_id' => ['required', 'integer'],
        ]);

        try {
            return response()->json($this->service->taskDetail((int) $data['task_id']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo consultar el detalle en el sistema externo (ADIST).'], 502);
        }
    }

    /** Crea las actividades para el mapa confirmado y encola la descarga de imágenes. */
    public function commit(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'request_id'     => ['required'],
            'user_id'        => ['required', 'integer', 'exists:users,id'],
            'type'           => ['nullable', 'string', 'max:60'],
            'target'         => ['nullable', 'in:activity,event'],
            // El mapa (task→device) es OBLIGATORIO para actividad, OPCIONAL para evento.
            'map'            => ['nullable', 'array'],
            'map.*'          => ['nullable', 'integer', 'exists:devices,id'],
            // Destino ACTIVIDAD
            'maintenance_id' => ['nullable', 'integer', 'exists:maintenances,id'],
            'dest_activity_type_id' => ['nullable', 'integer', 'exists:catalogs,id'],
            // Destino EVENTO
            'site_id'        => ['nullable', 'integer', 'exists:sites,id'],
            'system_id'      => ['nullable', 'integer', 'exists:catalogs,id'],
            'event_type_id'  => ['nullable', 'integer', 'exists:event_types,id'],
            'event_status_key' => ['nullable', 'string', 'max:60'],
            // Estado POR evento (override de la corrida) y subconjunto de tareas a importar (fila/bloque).
            'statuses'       => ['nullable', 'array'],
            'statuses.*'     => ['nullable', 'string', 'max:60'],
            'task_ids'       => ['nullable', 'array'],
            'task_ids.*'     => ['integer'],
            // Mapeo origen→destino {destFieldKey => sourceFieldKey}; se sanea en el servicio.
            'field_map'      => ['nullable', 'array'],
            // Mapeo de estados {nombreEstadoADIST => statusKey}; solo eventos, se aplica al crear.
            'status_name_map' => ['nullable', 'array'],
            'status_name_map.*' => ['nullable', 'string', 'max:60'],
        ]);

        $target = $data['target'] ?? 'activity';
        $type = strtoupper($data['type'] ?? 'PREVENTIVO');
        $userId = (int) $data['user_id'];

        // El mapa llega como { taskId: deviceId } con llaves string; normalizamos a int→int.
        $map = [];
        foreach (($data['map'] ?? []) as $taskId => $deviceId) {
            if ($deviceId) {
                $map[(int) $taskId] = (int) $deviceId;
            }
        }

        // Estado por evento { taskId: statusKey } y subconjunto de tareas a importar.
        $statusMap = [];
        foreach (($data['statuses'] ?? []) as $taskId => $key) {
            if ($key) {
                $statusMap[(int) $taskId] = (string) $key;
            }
        }
        $onlyTaskIds = isset($data['task_ids']) ? array_map('intval', $data['task_ids']) : null;
        $fieldMap = is_array($data['field_map'] ?? null) ? $data['field_map'] : [];

        // Mapeo de estados { nombreEstadoADIST => statusKey }.
        $statusNameMap = [];
        foreach (($data['status_name_map'] ?? []) as $name => $key) {
            if ($key) {
                $statusNameMap[(string) $name] = (string) $key;
            }
        }

        try {
            if ($target === 'event') {
                $request->validate([
                    'site_id'       => ['required', 'integer', 'exists:sites,id'],
                    'system_id'     => ['required', 'integer', 'exists:catalogs,id'],
                    'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
                ]);

                $result = $this->service->createEvents(
                    $data['request_id'], $type, $userId,
                    (int) $data['site_id'], (int) $data['system_id'], (int) $data['event_type_id'],
                    $data['event_status_key'] ?? null, $map, $statusMap, $onlyTaskIds, $fieldMap, $statusNameMap,
                );

                // Solo se descargan imágenes de eventos recién creados (evita duplicar en backfill).
                $queuedImages = false;
                if (! empty($result['new_pairs'])) {
                    ImportAdistImages::dispatch($result['new_pairs'], [], $userId, null, 'event');
                    $queuedImages = true;
                }

                return response()->json([
                    'created'       => $result['created'],
                    'updated'       => $result['updated'],
                    'queued_images' => $queuedImages,
                    'message'       => ($result['created'] ? "Se importaron {$result['created']} eventos" : 'No se crearon eventos nuevos')
                        .($result['updated'] ? " (y se rellenaron {$result['updated']} ya sincronizados)" : '')
                        .($queuedImages ? '. Las imágenes se están descargando en segundo plano; te avisaremos al terminar.' : '.'),
                ]);
            }

            // Destino ACTIVIDAD. El mapa de dispositivos es opcional (en backfill se conserva el existente).
            $request->validate([
                'maintenance_id' => ['required', 'integer', 'exists:maintenances,id'],
                'map'            => ['nullable', 'array'],
                'map.*'          => ['nullable', 'integer', 'exists:devices,id'],
            ]);
            $maintenance = Maintenance::findOrFail($data['maintenance_id']);

            $result = $this->service->createActivities(
                $maintenance, $data['request_id'], $type, $userId, $map,
                isset($data['dest_activity_type_id']) ? (int) $data['dest_activity_type_id'] : null,
                $onlyTaskIds, $fieldMap,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo importar desde el sistema externo (ADIST).'], 502);
        }

        // Solo se descargan imágenes de actividades recién creadas (evita duplicar en backfill).
        $queuedImages = false;
        if ($result['supports_images'] && ! empty($result['new_pairs'])) {
            ImportAdistImages::dispatch($result['new_pairs'], $result['imgKeys'], $userId, $maintenance->id);
            $queuedImages = true;
        }

        $backfilled = $result['backfilled'] ?? 0;
        $retyped    = $result['retyped'] ?? 0;

        $extra = [];
        if ($backfilled) {
            $extra[] = "se rellenaron {$backfilled} ya sincronizadas";
        }
        if ($retyped) {
            $extra[] = "se retipificaron {$retyped} (cambió su tipo de actividad)";
        }

        return response()->json([
            'created'        => $result['created'],
            'backfilled'     => $backfilled,
            'retyped'        => $retyped,
            'skipped'        => $result['skipped'],
            'queued_images'  => $queuedImages,
            'message'        => ($result['created'] ? "Se importaron {$result['created']} actividades" : 'No se crearon actividades nuevas')
                .($extra ? ' ('.implode(' y ', $extra).')' : '')
                .($queuedImages ? '. Las imágenes se están descargando en segundo plano; te avisaremos al terminar.' : '.'),
        ]);
    }
}
