<?php

namespace App\Services\Imports;

use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lógica canónica para importar TAREAS de un mantenimiento de ADIST3 (conexión `pruebas`)
 * como ACTIVIDADES de un mantenimiento nuestro, llenando los campos reales del formulario:
 *   - descripción (campo text)  ← Description de la tarea
 *   - imágenes (campos image)   ← S3Attachment de las NOTAS (se descargan y resuben)
 *
 * El match del dispositivo va por el `device` del additional_data contra el DID normalizado;
 * lo que no matchea deja CANDIDATOS (por tipo + cuarto) para que un humano confirme.
 *
 * Es el único lugar con esta lógica: lo usan tanto los comandos artisan (depuración) como el
 * asistente de importación en la interfaz (AdistImportController) y el Job de imágenes.
 */
class AdistImportService
{
    /** Normaliza un código/DID: mayúsculas, sin separadores, sin ceros a la izquierda por grupo de dígitos. */
    public function nz(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        $s = preg_replace('/[^A-Z0-9]/', '', $s);

        return preg_replace_callback('/[0-9]+/', fn ($m) => (string) intval($m[0]), $s);
    }

    /**
     * Resuelve el contexto de importación de un mantenimiento + tipo de actividad:
     * el tipo de actividad, las llaves de los campos de descripción e imagen del
     * formulario, y el índice DID→[device_id] del directorio destino.
     *
     * @return array{activityType: Catalog, descKey: ?string, imgKeys: array<int,string>, byDid: array<string,array<int,int>>, devices: \Illuminate\Support\Collection}
     */
    public function resolveContext(Maintenance $maintenance, string $type): array
    {
        $activityType = Catalog::where('type', Catalog::TYPE_ACTIVITY_TYPE)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($type)])
            ->first();

        if (! $activityType) {
            throw new \RuntimeException("No hay tipo de actividad que empate con «{$type}» en el sistema destino.");
        }

        $fields = DB::table('activity_type_fields')
            ->where('activity_type_id', $activityType->id)
            ->where('system_id', $maintenance->catalog_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $descKey = optional($fields->firstWhere('field_type', 'text'))->field_key;
        $imgKeys = $fields->where('field_type', 'image')->pluck('field_key')->values()->all();

        $devices = DB::table('devices as d')
            ->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $maintenance->site_id)
            ->where('dir.catalog_id', $maintenance->catalog_id)
            ->whereNull('d.archived_at')
            ->get([
                'd.id',
                DB::raw("d.custom_fields->>'did' as did"),
                DB::raw("d.custom_fields->>'tipo_dispositivo' as tipo"),
                DB::raw("d.custom_fields->>'dir_incendio_location' as loc"),
                DB::raw("d.custom_fields->>'dir_incendio_area' as area"),
            ]);

        $byDid = [];
        foreach ($devices as $d) {
            if ($d->did) {
                $byDid[$this->nz($d->did)][] = (int) $d->id;
            }
        }

        return compact('activityType', 'descKey', 'imgKeys', 'byDid', 'devices');
    }

    /** Trae las tareas de un IdRequest + tipo desde ADIST3 (con su `device` y quién la ejecutó). */
    public function fetchTasks(string|int $requestId, string $type): array
    {
        return DB::connection('pruebas')->select("
            SELECT trb.Id, trb.Title, trb.Description, trb.ExecutionDate, trb.DateCreate,
                   ad.Value AS device, p.Nombres AS assigned
            FROM t_maintenance_tasks trb
            LEFT JOIN t_maintenance_tasks_additional_data ad ON ad.TaskId = trb.Id AND ad.Field = 'device'
            LEFT JOIN t_rh_personal p ON p.IdUsuario = trb.IdAssigned
            WHERE trb.IdRequest = ? AND trb.IdTaskType = (SELECT Id FROM cat_maintenance_tasks_type WHERE Name = ? LIMIT 1)
            ORDER BY trb.Id
        ", [$requestId, $type]);
    }

    /**
     * Detalle COMPLETO de una tarea de ADIST para verlo en un drawer: encabezado (título,
     * tipo, estatus, prioridad, fechas, asignado/solicitante), campos extra (causa, solución,
     * costo, horas, tipo de cambio, dispositivo) y las notas con su autor e imágenes.
     *
     * @return array<string,mixed>
     */
    public function taskDetail(int $taskId): array
    {
        $pg = DB::connection('pruebas');

        $t = $pg->selectOne('
            SELECT t.Id, t.Title, t.Description, t.Advanced, t.ExecutionDate, t.DateCreate, t.EndDate,
                   ty.Name AS type_name, st.Name AS status_name, pr.Name AS priority_name,
                   asg.Nombres AS assigned_name, req.Nombres AS requester_name
            FROM t_maintenance_tasks t
            LEFT JOIN cat_maintenance_tasks_type ty ON ty.Id = t.IdTaskType
            LEFT JOIN cat_maintenance_status st ON st.Id = t.IdStatus
            LEFT JOIN cat_maintenance_priority pr ON pr.Id = t.IdPriority
            LEFT JOIN t_rh_personal asg ON asg.IdUsuario = t.IdAssigned
            LEFT JOIN t_rh_personal req ON req.IdUsuario = t.IdRequester
            WHERE t.Id = ?
        ', [$taskId]);

        if (! $t) {
            throw new \RuntimeException('La tarea no existe en el sistema externo.');
        }

        // Campos extra (additional_data). Resolvemos "cause" contra su catálogo.
        $extraRows = $pg->select('
            SELECT ad.Field, ad.Value,
                   cc.Name AS cause_name
            FROM t_maintenance_tasks_additional_data ad
            LEFT JOIN cat_maintenance_task_causes_types cc
                   ON ad.Field = \'cause\' AND cc.Id = CAST(NULLIF(ad.Value, \'\') AS UNSIGNED)
            WHERE ad.TaskId = ?
        ', [$taskId]);

        $labels = [
            'device'       => 'Dispositivo',
            'cause'        => 'Causa',
            'solution'     => 'Solución',
            'cost'         => 'Costo',
            'workHours'    => 'Horas de trabajo',
            'exchangeRate' => 'Tipo de cambio',
        ];
        $extra = [];
        foreach ($extraRows as $r) {
            $value = $r->Field === 'cause' && $r->cause_name ? $r->cause_name : (string) $r->Value;
            $value = trim(strip_tags($value));
            if ($value === '') {
                continue;
            }
            $extra[] = [
                'field' => $r->Field,
                'label' => $labels[$r->Field] ?? $r->Field,
                'value' => $value,
            ];
        }

        // Notas con autor e imágenes.
        $noteRows = $pg->select('
            SELECT n.Id, n.Message, n.S3Attachment, n.CreateDate, p.Nombres AS author
            FROM t_maintenance_tasks_notes n
            LEFT JOIN t_rh_personal p ON p.IdUsuario = n.IdUser
            WHERE n.IdTask = ?
            ORDER BY n.CreateDate
        ', [$taskId]);

        $notes = [];
        foreach ($noteRows as $n) {
            $images = [];
            if ($n->S3Attachment) {
                foreach (explode(',', $n->S3Attachment) as $u) {
                    $u = trim($u);
                    if ($u !== '') {
                        $images[] = $u;
                    }
                }
            }
            $notes[] = [
                'id'         => (int) $n->Id,
                'author'     => $n->author,
                'created_at' => $n->CreateDate,
                'message'    => trim(strip_tags((string) $n->Message)),
                'images'     => array_values(array_unique($images)),
            ];
        }

        return [
            'task_id'       => (int) $t->Id,
            'title'         => $t->Title,
            'description'   => trim(strip_tags((string) $t->Description)),
            'advanced'      => trim(strip_tags((string) $t->Advanced)),
            'type'          => $t->type_name,
            'status'        => $t->status_name,
            'priority'      => $t->priority_name,
            'assigned'      => $t->assigned_name,
            'requester'     => $t->requester_name,
            'execution_date' => $t->ExecutionDate,
            'created_at'    => $t->DateCreate,
            'end_date'      => $t->EndDate,
            'extra'         => $extra,
            'notes'         => $notes,
        ];
    }

    /** IDs de tareas que ya traen al menos una imagen en sus notas (para marcar has_images sin descargar). */
    public function tasksWithImages(array $taskIds): array
    {
        if (! $taskIds) {
            return [];
        }
        $rows = DB::connection('pruebas')
            ->table('t_maintenance_tasks_notes')
            ->whereIn('IdTask', $taskIds)
            ->whereNotNull('S3Attachment')
            ->where('S3Attachment', '!=', '')
            ->pluck('IdTask')
            ->all();

        return array_values(array_unique(array_map('intval', $rows)));
    }

    /**
     * Arma la vista previa: tareas auto-emparejadas por DID, las que faltan (con candidatos)
     * y las ya importadas; más el directorio completo para el selector de la interfaz.
     *
     * @return array<string,mixed>
     */
    public function preview(Maintenance $maintenance, string|int $requestId, string $type): array
    {
        $ctx = $this->resolveContext($maintenance, $type);
        $tasks = $this->fetchTasks($requestId, $type);

        $taskIds = array_map(fn ($t) => (int) $t->Id, $tasks);
        $withImages = array_flip($this->tasksWithImages($taskIds));
        $imported = MaintenanceActivity::whereIn('source_ref', array_map(fn ($id) => 'adist3:'.$id, $taskIds))
            ->pluck('source_ref')->map(fn ($r) => (int) Str::after($r, 'adist3:'))->flip()->all();

        $out = [];
        $matched = 0;
        $unmatched = 0;
        foreach ($tasks as $t) {
            $id = (int) $t->Id;
            $key = $this->nz($t->device);
            $deviceId = null;
            $candidates = [];
            $status = 'unmatched';

            if (isset($imported[$id])) {
                $status = 'imported';
                $deviceId = optional(MaintenanceActivity::where('source_ref', 'adist3:'.$id)->first())->device_id;
            } elseif ($t->device && isset($ctx['byDid'][$key]) && count($ctx['byDid'][$key]) === 1) {
                $status = 'matched';
                $deviceId = $ctx['byDid'][$key][0];
                $matched++;
            } else {
                $text = $t->Title.' '.strip_tags((string) $t->Description);
                $candidates = collect($this->candidates($maintenance, $this->typePattern($text), $this->rooms($text)))
                    ->pluck('id')->map(fn ($x) => (int) $x)->all();
                $unmatched++;
            }

            $out[] = [
                'task_id'      => $id,
                'device_code'  => $t->device,
                'performed_at' => $t->ExecutionDate,
                'assigned'     => $t->assigned,
                'description'  => trim(strip_tags((string) $t->Description)),
                'has_images'   => isset($withImages[$id]),
                'status'       => $status,
                'device_id'    => $deviceId,
                'candidates'   => $candidates,
            ];
        }

        $maintenance->loadMissing(['site.client', 'system']);

        return [
            'maintenance' => [
                'id'     => $maintenance->id,
                'client' => optional(optional($maintenance->site)->client)->name,
                'site'   => optional($maintenance->site)->name,
                'system' => optional($maintenance->system)->label,
            ],
            'form' => [
                'supports_description' => (bool) $ctx['descKey'],
                'supports_images'      => count($ctx['imgKeys']) > 0,
            ],
            'counts' => [
                'total'      => count($tasks),
                'matched'    => $matched,
                'unmatched'  => $unmatched,
                'imported'   => count($imported),
            ],
            'devices' => $ctx['devices']->map(fn ($d) => [
                'id'    => (int) $d->id,
                'did'   => $d->did,
                'tipo'  => $d->tipo,
                'loc'   => $d->loc,
                'label' => trim(($d->did ?: '¿?').' · '.($d->tipo ?: '—').($d->loc ? ' · '.$d->loc : '')),
            ])->values()->all(),
            'tasks' => $out,
        ];
    }

    /**
     * Crea/actualiza las actividades para el mapa {task_id => device_id}. NO baja imágenes
     * (eso lo hace el Job en segundo plano). Idempotente por source_ref.
     *
     * @param  array<int,int>  $map
     * @return array{created: int, pairs: array<int,array{task_id:int, activity_id:int}>, skipped: int, imgKeys: array<int,string>, supports_images: bool}
     */
    public function createActivities(Maintenance $maintenance, string|int $requestId, string $type, int $userId, array $map): array
    {
        $ctx = $this->resolveContext($maintenance, $type);
        $tasks = $this->fetchTasks($requestId, $type);

        $created = 0;
        $skipped = 0;
        $pairs = [];

        foreach ($tasks as $t) {
            $id = (int) $t->Id;
            $deviceId = $map[$id] ?? null;
            if (! $deviceId) {
                $skipped++;
                continue;
            }

            $fv = ['_origen' => [
                'sistema'     => 'adist3',
                'task_id'     => $id,
                'device_code' => $t->device,
                'asignado'    => $t->assigned,
                'date_create' => $t->DateCreate,
            ]];
            if ($ctx['descKey']) {
                $fv[$ctx['descKey']] = trim(strip_tags((string) $t->Description));
            }

            $activity = MaintenanceActivity::updateOrCreate(
                ['source_ref' => 'adist3:'.$id],
                [
                    'maintenance_id'   => $maintenance->id,
                    'device_id'        => (int) $deviceId,
                    'activity_type_id' => $ctx['activityType']->id,
                    'user_id'          => $userId,
                    'performed_at'     => $t->ExecutionDate,
                    'field_values'     => $fv,
                ],
            );

            $created++;
            $pairs[] = ['task_id' => $id, 'activity_id' => $activity->id];
        }

        return [
            'created'         => $created,
            'pairs'           => $pairs,
            'skipped'         => $skipped,
            'imgKeys'         => $ctx['imgKeys'],
            'supports_images' => count($ctx['imgKeys']) > 0,
        ];
    }

    /** URLs de imágenes (S3) de las notas de una tarea. */
    public function taskImageUrls(int $taskId): array
    {
        $notes = DB::connection('pruebas')->select(
            'SELECT S3Attachment FROM t_maintenance_tasks_notes WHERE IdTask = ?',
            [$taskId]
        );
        $urls = [];
        foreach ($notes as $n) {
            if (! $n->S3Attachment) {
                continue;
            }
            foreach (explode(',', $n->S3Attachment) as $u) {
                $u = trim($u);
                if ($u !== '') {
                    $urls[] = $u;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** Descarga cada imagen de S3 y la resube a nuestro storage; devuelve nuestras URLs. */
    public function importImages(array $s3urls): array
    {
        $out = [];
        foreach ($s3urls as $url) {
            try {
                $res = Http::timeout(30)->get($url);
                if (! $res->successful()) {
                    continue;
                }
                $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $path = 'maintenance-media/adist-'.Str::uuid().'.'.strtolower($ext);
                Storage::disk('public')->put($path, $res->body());
                $out[] = Storage::disk('public')->url($path);
            } catch (\Throwable $e) {
                // imagen omitida
            }
        }

        return $out;
    }

    /**
     * Llena las imágenes de UNA actividad ya creada (descarga de la tarea y mezcla en field_values).
     * Devuelve cuántas imágenes se subieron.
     *
     * @param  array<int,string>  $imgKeys
     */
    public function fillActivityImages(MaintenanceActivity $activity, int $taskId, array $imgKeys): int
    {
        if (! $imgKeys) {
            return 0;
        }
        $our = $this->importImages($this->taskImageUrls($taskId));
        if (! $our) {
            return 0;
        }

        $fv = $activity->field_values ?? [];
        $fv[$imgKeys[0]] = array_slice($our, 0, 1);
        if (isset($imgKeys[1]) && count($our) > 1) {
            $fv[$imgKeys[1]] = array_slice($our, 1);
        }
        $activity->update(['field_values' => $fv]);

        return count($our);
    }

    // ── Reconciliación (candidatos por tipo + cuarto) ───────────────────────────

    /** Palabra(s) de tipo → patrón ILIKE contra nuestro tipo_dispositivo. */
    public function typePattern(string $text): ?string
    {
        $t = strtoupper($text);

        return match (true) {
            str_contains($t, 'MULTICRITERIO')                                             => '%MULTI%',
            str_contains($t, 'DETECTOR DE HUMO') || str_contains($t, 'DETECTOR DE  HUMO')  => '%DETECTOR%HUMO%',
            str_contains($t, 'ESTROBO')                                                    => '%ESTROBO%',
            str_contains($t, 'FUENTE DE PODER') || str_contains($t, 'FUENTA DE PODER')     => '%FUENTE%',
            str_contains($t, 'ANUNCIADOR')                                                 => '%ANUNCIADOR%',
            str_contains($t, 'FOTOBEAM') || str_contains($t, 'PHOTOBEAM')                  => '%FOTOBEAM%',
            str_contains($t, 'ESTACION MANUAL') || str_contains($t, 'ESTACIÓN MANUAL')     => '%ESTACION%',
            str_contains($t, 'MODULO') || str_contains($t, 'TARJETA') || str_contains($t, 'PANEL') => '%PANEL%',
            str_contains($t, 'BASE')                                                       => '%BASE%',
            default                                                                        => null,
        };
    }

    /** Extrae tokens de habitación/ubicación del texto. */
    public function rooms(string $text): array
    {
        $t = strtoupper($text);
        $rooms = [];
        if (preg_match_all('/HAB(?:ITACION|ITACIÓN)?\s*0*(\d{2,4})/', $t, $m)) {
            $rooms = array_merge($rooms, $m[1]);
        }
        foreach (['LOBBY', 'PASILLO', 'COCINA', 'RESTAURANT', 'REST ', 'TORRE', 'CASETA', 'BAÑO', 'SPA', 'GYM', 'ALBERCA'] as $w) {
            if (str_contains($t, $w)) {
                $rooms[] = trim($w);
            }
        }

        return array_values(array_unique($rooms));
    }

    /** Candidatos de nuestro directorio por tipo + cuarto (rankeados). Devuelve stdClass{id,did,tipo,loc,area}. */
    public function candidates(Maintenance $m, ?string $typePattern, array $rooms): array
    {
        $base = fn () => DB::table('devices as d')
            ->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $m->site_id)->where('dir.catalog_id', $m->catalog_id)->whereNull('d.archived_at')
            ->select('d.id',
                DB::raw("d.custom_fields->>'did' as did"),
                DB::raw("d.custom_fields->>'tipo_dispositivo' as tipo"),
                DB::raw("d.custom_fields->>'dir_incendio_location' as loc"),
                DB::raw("d.custom_fields->>'dir_incendio_area' as area"));

        $roomWhere = function ($q) use ($rooms) {
            $q->where(function ($w) use ($rooms) {
                foreach ($rooms as $r) {
                    $w->orWhereRaw("UPPER(d.custom_fields->>'dir_incendio_location') LIKE ?", ['%'.strtoupper($r).'%'])
                      ->orWhereRaw("UPPER(d.custom_fields->>'dir_incendio_area') LIKE ?", ['%'.strtoupper($r).'%']);
                }
            });
        };

        // 1) tipo + cuarto
        if ($typePattern && $rooms) {
            $r = $base()->whereRaw("UPPER(d.custom_fields->>'tipo_dispositivo') LIKE ?", [$typePattern])->where($roomWhere)->limit(3)->get();
            if ($r->count()) {
                return $r->all();
            }
        }
        // 2) solo cuarto
        if ($rooms) {
            $r = $base()->where($roomWhere)->limit(3)->get();
            if ($r->count()) {
                return $r->all();
            }
        }
        // 3) solo tipo
        if ($typePattern) {
            return $base()->whereRaw("UPPER(d.custom_fields->>'tipo_dispositivo') LIKE ?", [$typePattern])->limit(3)->get()->all();
        }

        return [];
    }
}
