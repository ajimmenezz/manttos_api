<?php

namespace App\Services\Imports;

use App\Models\Catalog;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventStatusHistory;
use App\Models\EventType;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\Site;
use App\Support\EventFolio;
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
    public function resolveContext(Maintenance $maintenance, string $type, ?int $destActivityTypeId = null): array
    {
        // Destino: tipo de actividad EXPLÍCITO (id) si se indica; si no, por nombre (compat).
        $activityType = $destActivityTypeId
            ? Catalog::where('type', Catalog::TYPE_ACTIVITY_TYPE)->find($destActivityTypeId)
            : Catalog::where('type', Catalog::TYPE_ACTIVITY_TYPE)
                ->whereRaw('LOWER(label) = ?', [mb_strtolower($type)])->first();

        if (! $activityType) {
            throw new \RuntimeException($destActivityTypeId
                ? 'El tipo de actividad destino no existe.'
                : "No hay tipo de actividad que empate con «{$type}» en el sistema destino.");
        }

        $fields = DB::table('activity_type_fields')
            ->where('activity_type_id', $activityType->id)
            ->where('system_id', $maintenance->catalog_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $descKey = optional($fields->firstWhere('field_type', 'text'))->field_key;
        $imgKeys = $fields->where('field_type', 'image')->pluck('field_key')->values()->all();

        $maintenance->loadMissing('site');
        ['devices' => $devices, 'byDid' => $byDid] = $this->deviceIndex(
            $maintenance->site_id, $maintenance->catalog_id, optional($maintenance->site)->client_id
        );

        return compact('activityType', 'descKey', 'imgKeys', 'byDid', 'devices');
    }

    /**
     * Índice de dispositivos del directorio (sitio × sistema): la colección (con etiqueta y
     * texto buscable) y el mapa DID normalizado → [device_id]. Reusado por la importación a
     * actividades y a eventos.
     *
     * GENÉRICO — no asume la plantilla de incendios: resuelve el campo DID por su
     * `field_type='did'` (fallback 'did') y arma el texto de búsqueda con TODOS los valores
     * del directorio (nombre, tipo, ubicación nativa y cualquier campo personalizado), de modo
     * que se pueda buscar por DID, área, ubicación o lo que exista en el directorio.
     *
     * @return array{devices: \Illuminate\Support\Collection, byDid: array<string,array<int,int>>}
     */
    private function deviceIndex(int $siteId, int $systemId, ?int $clientId = null): array
    {
        $didKey = $this->didKeyFor($systemId, $clientId);

        $rows = DB::table('devices as d')
            ->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $siteId)
            ->where('dir.catalog_id', $systemId)
            ->whereNull('d.archived_at')
            ->get(['d.id', 'd.name', 'd.device_type', 'd.location', 'd.custom_fields']);

        $devices = $rows->map(function ($d) use ($didKey) {
            $cf = $this->decodeCustom($d->custom_fields);
            $did  = trim((string) ($cf[$didKey] ?? '')) ?: trim((string) $d->name);
            $tipo = trim((string) $d->device_type);
            // Todos los demás valores del directorio como texto (para buscar por ubicación/área/etc.).
            $cfText = collect($cf)->forget($didKey)->flatMap(fn ($v) => $this->flatStrings($v))->filter()->values();
            $loc = trim((string) $d->location) ?: $cfText->take(5)->implode(' · ');
            $search = strtoupper(trim($did.' '.$tipo.' '.$d->location.' '.$cfText->implode(' ')));

            return (object) [
                'id'     => (int) $d->id,
                'did'    => $did !== '' ? $did : null,
                'tipo'   => $tipo !== '' ? $tipo : null,
                'loc'    => $loc !== '' ? $loc : null,
                'search' => $search,
            ];
        })->values();

        $byDid = [];
        foreach ($devices as $d) {
            if ($d->did) {
                $byDid[$this->nz($d->did)][] = (int) $d->id;
            }
        }

        return compact('devices', 'byDid');
    }

    /** Clave real del campo DID del sistema (`field_type='did'`, override por cliente); fallback 'did'. */
    private function didKeyFor(int $systemId, ?int $clientId): string
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

    /** Normaliza el `custom_fields` (jsonb string o array) a un arreglo asociativo. */
    private function decodeCustom($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** Aplana un valor de campo (escalar o arreglo) a cadenas de texto, omitiendo URLs/imágenes. */
    private function flatStrings($v): array
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $x) {
                $out = array_merge($out, $this->flatStrings($x));
            }

            return $out;
        }
        if ($v === null || is_bool($v)) {
            return [];
        }
        $s = trim((string) $v);
        if ($s === '' || Str::startsWith($s, ['http://', 'https://'])) {
            return [];
        }

        return [$s];
    }

    /** Serializa la colección de dispositivos (del deviceIndex) al shape que consume la interfaz. */
    private function devicePayload($devices): array
    {
        return collect($devices)->map(fn ($d) => [
            'id'     => (int) $d->id,
            'did'    => $d->did,
            'tipo'   => $d->tipo,
            'loc'    => $d->loc,
            'label'  => trim(($d->did ?: '¿?').' · '.($d->tipo ?: '—').($d->loc ? ' · '.$d->loc : '')),
            'search' => $d->search,
        ])->values()->all();
    }

    /** Tipos de tarea DISTINTOS de un IdRequest (para el desplegable), con su conteo. */
    public function taskTypes(string|int $requestId): array
    {
        return DB::connection('pruebas')->select("
            SELECT ty.Name AS name, COUNT(*) AS n
            FROM t_maintenance_tasks t
            JOIN cat_maintenance_tasks_type ty ON ty.Id = t.IdTaskType
            WHERE t.IdRequest = ?
            GROUP BY ty.Name
            ORDER BY ty.Name
        ", [$requestId]);
    }

    /** Trae las tareas de un IdRequest + tipo desde ADIST3 con TODOS los campos mapeables. */
    public function fetchTasks(string|int $requestId, string $type): array
    {
        return DB::connection('pruebas')->select("
            SELECT trb.Id, trb.Title, trb.Description, trb.Advanced,
                   trb.ExecutionDate, trb.DateCreate, trb.EndDate,
                   ty.Name AS type_name, st.Name AS status_name, pr.Name AS priority_name,
                   asg.Nombres AS assigned, req.Nombres AS requester,
                   (SELECT ad.Value FROM t_maintenance_tasks_additional_data ad
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'device' LIMIT 1)       AS device,
                   (SELECT ad.Value FROM t_maintenance_tasks_additional_data ad
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'solution' LIMIT 1)     AS solution,
                   (SELECT ad.Value FROM t_maintenance_tasks_additional_data ad
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'cost' LIMIT 1)         AS cost,
                   (SELECT ad.Value FROM t_maintenance_tasks_additional_data ad
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'workHours' LIMIT 1)    AS work_hours,
                   (SELECT ad.Value FROM t_maintenance_tasks_additional_data ad
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'exchangeRate' LIMIT 1) AS exchange_rate,
                   (SELECT cc.Name FROM t_maintenance_tasks_additional_data ad
                      JOIN cat_maintenance_task_causes_types cc
                        ON cc.Id = CAST(NULLIF(ad.Value, '') AS UNSIGNED)
                      WHERE ad.TaskId = trb.Id AND ad.Field = 'cause' LIMIT 1)        AS cause
            FROM t_maintenance_tasks trb
            LEFT JOIN cat_maintenance_tasks_type ty ON ty.Id = trb.IdTaskType
            LEFT JOIN cat_maintenance_status st ON st.Id = trb.IdStatus
            LEFT JOIN cat_maintenance_priority pr ON pr.Id = trb.IdPriority
            LEFT JOIN t_rh_personal asg ON asg.IdUsuario = trb.IdAssigned
            LEFT JOIN t_rh_personal req ON req.IdUsuario = trb.IdRequester
            WHERE trb.IdRequest = ? AND trb.IdTaskType = (SELECT Id FROM cat_maintenance_tasks_type WHERE Name = ? LIMIT 1)
            ORDER BY trb.Id
        ", [$requestId, $type]);
    }

    /**
     * Catálogo canónico de campos de ORIGEN (ADIST) disponibles para el mapeo.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function sourceFields(): array
    {
        return [
            ['key' => 'title',          'label' => 'Título'],
            ['key' => 'description',    'label' => 'Descripción'],
            ['key' => 'advanced',       'label' => 'Avance / notas adicionales'],
            ['key' => 'execution_date', 'label' => 'Fecha de ejecución'],
            ['key' => 'date_create',    'label' => 'Fecha de creación'],
            ['key' => 'end_date',       'label' => 'Fecha de finalización'],
            ['key' => 'type',           'label' => 'Tipo de tarea'],
            ['key' => 'status',         'label' => 'Estatus'],
            ['key' => 'priority',       'label' => 'Prioridad'],
            ['key' => 'assigned',       'label' => 'Asignado'],
            ['key' => 'requester',      'label' => 'Solicitante'],
            ['key' => 'device',         'label' => 'Dispositivo (código)'],
            ['key' => 'cause',          'label' => 'Causa'],
            ['key' => 'solution',       'label' => 'Solución'],
            ['key' => 'cost',           'label' => 'Costo'],
            ['key' => 'work_hours',     'label' => 'Horas de trabajo'],
            ['key' => 'exchange_rate',  'label' => 'Tipo de cambio'],
        ];
    }

    /** Extrae el valor de un campo de origen de una fila de tarea de ADIST. */
    private function sourceValue(object $t, string $key): mixed
    {
        return match ($key) {
            'title'          => $t->Title ?? null,
            'description'    => $t->Description ?? null,
            'advanced'       => $t->Advanced ?? null,
            'execution_date' => $t->ExecutionDate ?? null,
            'date_create'    => $t->DateCreate ?? null,
            'end_date'       => $t->EndDate ?? null,
            'type'           => $t->type_name ?? null,
            'status'         => $t->status_name ?? null,
            'priority'       => $t->priority_name ?? null,
            'assigned'       => $t->assigned ?? null,
            'requester'      => $t->requester ?? null,
            'device'         => $t->device ?? null,
            'cause'          => $t->cause ?? null,
            'solution'       => $t->solution ?? null,
            'cost'           => $t->cost ?? null,
            'work_hours'     => $t->work_hours ?? null,
            'exchange_rate'  => $t->exchange_rate ?? null,
            default          => null,
        };
    }

    // ── Mapeo de campos origen → destino ────────────────────────────────────────

    /**
     * Describe los campos del formulario destino (tipo + opciones resueltas) para el
     * asistente de mapeo y para la coerción de valores. Recibe modelos Eloquent de
     * campo (ActivityTypeField/EventTypeField) para aprovechar el accessor `config`
     * que resuelve las opciones de las listas personalizadas (catálogo propio).
     *
     * @param  \Illuminate\Support\Collection|array  $fields
     * @return array<int,array{field_key:string,label:string,field_type:string,options:array,catalog_type:?string}>
     */
    public function describeDestFields($fields): array
    {
        return collect($fields)->map(function ($f) {
            $type = $f->field_type;
            $options = [];

            if (in_array($type, ['custom_list', 'custom_multiselect'], true)) {
                $cfg = $f->config ?? [];
                $options = collect($cfg['options'] ?? [])
                    ->map(fn ($o) => [
                        'label' => (string) ($o['label'] ?? $o['value'] ?? ''),
                        'value' => (string) ($o['value'] ?? $o['label'] ?? ''),
                    ])
                    ->filter(fn ($o) => $o['value'] !== '')
                    ->values()->all();
            } elseif (in_array($type, ['list', 'multiselect'], true) && $f->catalog_type) {
                $options = Catalog::ofType($f->catalog_type)->orderBy('label')->pluck('label')
                    ->map(fn ($l) => ['label' => (string) $l, 'value' => (string) $l])
                    ->all();
            }

            return [
                'field_key'    => $f->field_key,
                'label'        => $f->label,
                'field_type'   => $type,
                'options'      => $options,
                'catalog_type' => $f->catalog_type,
            ];
        })->values()->all();
    }

    /** Mapa por defecto (reproduce el comportamiento histórico): primer campo `text` ← Descripción. */
    public function defaultMap(array $destFields): array
    {
        foreach ($destFields as $f) {
            if ($f['field_type'] === 'text') {
                return [$f['field_key'] => 'description'];
            }
        }

        return [];
    }

    /** Normaliza una cadena para comparar sin distinguir mayúsculas ni acentos. */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Convierte un valor de ORIGEN al formato que espera el campo DESTINO.
     * Listas: auto-match por etiqueta/valor; si no empata, deja el valor crudo (fallback).
     *
     * @param  array{field_type:string,options?:array,catalog_type?:?string}  $field
     */
    public function coerceValue(array $field, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        $s = trim(strip_tags((string) $raw));
        if ($s === '') {
            return null;
        }

        return match ($field['field_type'] ?? 'text') {
            'text', 'textarea'     => $s,
            'number', 'currency', 'scale' => $this->toNumber($s),
            'date'                 => $this->toDate($s, 'Y-m-d'),
            'datetime'             => $this->toDate($s, 'Y-m-d H:i:s'),
            'time'                 => $this->toDate($s, 'H:i'),
            'boolean'              => $this->toBool($s),
            'list'                 => $this->matchCatalog($field['catalog_type'] ?? null, $s),
            'multiselect'          => array_values(array_filter([$this->matchCatalog($field['catalog_type'] ?? null, $s)], fn ($v) => $v !== null && $v !== '')),
            'custom_list'          => $this->matchOption($field['options'] ?? [], $s),
            'custom_multiselect'   => array_values(array_filter([$this->matchOption($field['options'] ?? [], $s)], fn ($v) => $v !== null && $v !== '')),
            default                => null, // image/signature/leyenda: no se llenan por mapeo
        };
    }

    /** Limpia separadores y devuelve un número (o el crudo si no es numérico). */
    private function toNumber(string $s): mixed
    {
        $clean = str_replace([',', ' ', '$'], '', $s);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean);

        return is_numeric($clean) ? (str_contains($clean, '.') ? (float) $clean : (int) $clean) : $s;
    }

    /** Parsea a fecha en el formato pedido; si falla, devuelve el crudo. */
    private function toDate(string $s, string $format): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($s)->format($format);
        } catch (\Throwable) {
            return $s;
        }
    }

    private function toBool(string $s): bool
    {
        return ! in_array($this->norm($s), ['0', 'false', 'no', 'n', 'falso', ''], true);
    }

    /** Empata contra las etiquetas de un catálogo del sistema; fallback: crudo. */
    private function matchCatalog(?string $catalogType, string $s): string
    {
        if (! $catalogType) {
            return $s;
        }
        static $cache = [];
        if (! isset($cache[$catalogType])) {
            $cache[$catalogType] = Catalog::ofType($catalogType)->pluck('label')->all();
        }
        $key = $this->norm($s);
        foreach ($cache[$catalogType] as $label) {
            if ($this->norm((string) $label) === $key) {
                return (string) $label;
            }
        }

        return $s;
    }

    /** Empata contra las opciones de una lista personalizada (etiqueta o valor); fallback: crudo. */
    private function matchOption(array $options, string $s): string
    {
        $key = $this->norm($s);
        foreach ($options as $o) {
            if ($this->norm((string) ($o['label'] ?? '')) === $key || $this->norm((string) ($o['value'] ?? '')) === $key) {
                return (string) ($o['value'] ?? $o['label'] ?? $s);
            }
        }

        return $s;
    }

    /**
     * Construye los valores mapeados {field_key => valor coercido} para una tarea.
     *
     * @param  array<string,string>  $fieldMap    destKey => sourceKey
     * @param  array<string,array>   $destByKey   describeDestFields() indexado por field_key
     */
    private function buildMappedValues(array $fieldMap, array $destByKey, object $task): array
    {
        $out = [];
        foreach ($fieldMap as $destKey => $sourceKey) {
            if (! $sourceKey || ! isset($destByKey[$destKey])) {
                continue;
            }
            $val = $this->coerceValue($destByKey[$destKey], $this->sourceValue($task, (string) $sourceKey));
            if ($val !== null && $val !== '' && $val !== []) {
                $out[$destKey] = $val;
            }
        }

        return $out;
    }

    /** Sanea el field_map recibido: descarta llaves destino/origen desconocidas. */
    private function sanitizeFieldMap(array $fieldMap, array $destByKey): array
    {
        $sources = array_column($this->sourceFields(), 'key');
        $clean = [];
        foreach ($fieldMap as $destKey => $sourceKey) {
            if (isset($destByKey[$destKey]) && in_array((string) $sourceKey, $sources, true)) {
                $clean[(string) $destKey] = (string) $sourceKey;
            }
        }

        return $clean;
    }

    /** Campos destino (Eloquent, con config resuelta) de un mantenimiento + tipo de actividad. */
    private function activityDestFieldModels(Maintenance $maintenance, string $type, ?int $destActivityTypeId): array
    {
        $ctx = $this->resolveContext($maintenance, $type, $destActivityTypeId);
        $fields = \App\Models\ActivityTypeField::where('activity_type_id', $ctx['activityType']->id)
            ->where('system_id', $maintenance->catalog_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        return $this->describeDestFields($fields);
    }

    /** Campos destino (Eloquent, con config resuelta) de un tipo de evento × sistema. */
    private function eventDestFieldModels(int $eventTypeId, int $systemId): array
    {
        $fields = \App\Models\EventTypeField::where('event_type_id', $eventTypeId)
            ->where('system_id', $systemId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        return $this->describeDestFields($fields);
    }

    /**
     * Payload del asistente de mapeo: campos de origen + campos destino + mapa por defecto.
     * `$destActivityTypeId` requiere que el tipo de actividad destino sea explícito para actividad.
     *
     * @return array{source_fields:array,dest_fields:array,default_map:array}
     */
    public function fieldsPayload(string $target, string $type, ?Maintenance $maintenance, ?int $destActivityTypeId, ?int $eventTypeId, ?int $systemId): array
    {
        $destFields = $target === 'event'
            ? $this->eventDestFieldModels((int) $eventTypeId, (int) $systemId)
            : $this->activityDestFieldModels($maintenance, $type, $destActivityTypeId);

        return [
            'source_fields' => $this->sourceFields(),
            'dest_fields'   => $destFields,
            'default_map'   => $this->defaultMap($destFields),
        ];
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
    public function preview(Maintenance $maintenance, string|int $requestId, string $type, ?int $destActivityTypeId = null): array
    {
        $ctx = $this->resolveContext($maintenance, $type, $destActivityTypeId);
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
            'devices' => $this->devicePayload($ctx['devices']),
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
    public function createActivities(Maintenance $maintenance, string|int $requestId, string $type, int $userId, array $map, ?int $destActivityTypeId = null, ?array $onlyTaskIds = null, array $fieldMap = []): array
    {
        $ctx = $this->resolveContext($maintenance, $type, $destActivityTypeId);
        $tasks = $this->fetchTasks($requestId, $type);
        $onlySet = $onlyTaskIds !== null ? array_flip(array_map('intval', $onlyTaskIds)) : null;

        // Campos destino (con opciones resueltas) para el mapeo, indexados por field_key.
        $destByKey = [];
        if ($fieldMap) {
            $destByKey = collect($this->activityDestFieldModels($maintenance, $type, $destActivityTypeId))->keyBy('field_key')->all();
            $fieldMap = $this->sanitizeFieldMap($fieldMap, $destByKey);
        }

        $created = 0;
        $backfilled = 0;
        $skipped = 0;
        $pairs = [];      // todos (para imágenes de nuevos)
        $newPairs = [];   // solo creados

        foreach ($tasks as $t) {
            $id = (int) $t->Id;
            if ($onlySet !== null && ! isset($onlySet[$id])) {
                continue;
            }

            $existing = MaintenanceActivity::where('source_ref', 'adist3:'.$id)->first();
            $isNew = ! $existing;
            // Nuevo: requiere dispositivo (map). Existente (backfill): conserva el suyo salvo override.
            $deviceId = $map[$id] ?? ($existing?->device_id);
            if (! $deviceId) {
                $skipped++;
                continue;
            }

            $mapped = $fieldMap ? $this->buildMappedValues($fieldMap, $destByKey, $t) : [];

            // field_values: parte del existente (backfill preserva lo capturado), o vacío al crear.
            $fv = $isNew ? [] : (is_array($existing->field_values) ? $existing->field_values : []);
            $fv['_origen'] = [
                'sistema'     => 'adist3',
                'task_id'     => $id,
                'device_code' => $t->device,
                'asignado'    => $t->assigned,
                'date_create' => $t->DateCreate,
            ];
            // Descripción por defecto solo al crear y si el mapa no la redefine (compat histórica).
            if ($isNew && $ctx['descKey'] && ! isset($mapped[$ctx['descKey']])) {
                $fv[$ctx['descKey']] = trim(strip_tags((string) $t->Description));
            }
            foreach ($mapped as $k => $v) {
                $fv[$k] = $v; // sobrescribe la llave mapeada
            }

            if ($isNew) {
                $activity = MaintenanceActivity::create([
                    'source_ref'       => 'adist3:'.$id,
                    'maintenance_id'   => $maintenance->id,
                    'device_id'        => (int) $deviceId,
                    'activity_type_id' => $ctx['activityType']->id,
                    'user_id'          => $userId,
                    'performed_at'     => $t->ExecutionDate,
                    'field_values'     => $fv,
                ]);
                $created++;
                $newPairs[] = ['task_id' => $id, 'activity_id' => $activity->id];
            } else {
                // Backfill: solo campos + dispositivo si hay override; conserva fecha/tipo/usuario.
                $update = ['field_values' => $fv];
                if (isset($map[$id])) {
                    $update['device_id'] = (int) $map[$id];
                }
                $existing->update($update);
                $activity = $existing;
                $backfilled++;
            }

            $pairs[] = ['task_id' => $id, 'activity_id' => $activity->id];
        }

        return [
            'created'         => $created,
            'backfilled'      => $backfilled,
            'pairs'           => $pairs,
            'new_pairs'       => $newPairs,
            'skipped'         => $skipped,
            'imgKeys'         => $ctx['imgKeys'],
            'supports_images' => count($ctx['imgKeys']) > 0,
        ];
    }

    // ── Importación como EVENTOS (correctivos que en ADIST vivían dentro del mantenimiento) ──

    /**
     * Vista previa de la importación como EVENTOS: sitio/sistema/tipo de evento GLOBALES de la
     * corrida. El dispositivo es OPCIONAL (auto-match por DID único donde exista); las tareas
     * sin dispositivo igual se importan. Idempotente por events.source_ref.
     *
     * @return array<string,mixed>
     */
    public function previewEvents(int $siteId, int $systemId, int $eventTypeId, string|int $requestId, string $sourceType): array
    {
        $site = Site::with('client')->findOrFail($siteId);
        $eventType = EventType::findOrFail($eventTypeId);
        ['devices' => $devices, 'byDid' => $byDid] = $this->deviceIndex($siteId, $systemId, $site->client_id);
        $tasks = $this->fetchTasks($requestId, $sourceType);

        $taskIds = array_map(fn ($t) => (int) $t->Id, $tasks);
        $withImages = array_flip($this->tasksWithImages($taskIds));
        $imported = Event::whereIn('source_ref', array_map(fn ($id) => 'adist3:'.$id, $taskIds))
            ->pluck('source_ref')->map(fn ($r) => (int) Str::after($r, 'adist3:'))->flip()->all();

        $out = [];
        $matched = 0;
        foreach ($tasks as $t) {
            $id = (int) $t->Id;
            $key = $this->nz($t->device);
            $deviceId = null;
            $status = 'no_device';

            if (isset($imported[$id])) {
                $status = 'imported';
                $deviceId = optional(Event::where('source_ref', 'adist3:'.$id)->first())->device_id;
            } elseif ($t->device && isset($byDid[$key]) && count($byDid[$key]) === 1) {
                $status = 'matched';
                $deviceId = $byDid[$key][0];
                $matched++;
            } elseif ($t->device) {
                $status = 'unmatched'; // tiene código pero no empató (evento se crea sin dispositivo)
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
            ];
        }

        return [
            'target' => 'event',
            'event'  => [
                'client'     => optional($site->client)->name,
                'site'       => $site->name,
                'event_type' => $eventType->label,
            ],
            'counts' => [
                'total'    => count($tasks),
                'matched'  => $matched,
                'imported' => count($imported),
            ],
            'devices' => $this->devicePayload($devices),
            'tasks' => $out,
        ];
    }

    /**
     * Crea/actualiza EVENTOS para las tareas de un IdRequest+tipo. Sitio/sistema/tipo de evento
     * globales; dispositivo opcional (map override o auto-match DID). NO baja imágenes (Job).
     * Idempotente por source_ref (folio solo al crear).
     *
     * @param  array<int,int>  $map  overrides {task_id => device_id}
     * @return array{created:int, updated:int, pairs:array<int,array{task_id:int, event_id:int}>, supports_images:bool}
     */
    public function createEvents(string|int $requestId, string $sourceType, int $userId, int $siteId, int $systemId, int $eventTypeId, ?string $statusKey, array $map = [], array $statusMap = [], ?array $onlyTaskIds = null, array $fieldMap = []): array
    {
        $site = Site::with('client')->findOrFail($siteId);
        $eventType = EventType::findOrFail($eventTypeId);
        ['byDid' => $byDid] = $this->deviceIndex($siteId, $systemId, $site->client_id);

        // Campos destino (con opciones resueltas) para el mapeo, indexados por field_key.
        $destByKey = [];
        if ($fieldMap) {
            $destByKey = collect($this->eventDestFieldModels($eventTypeId, $systemId))->keyBy('field_key')->all();
            $fieldMap = $this->sanitizeFieldMap($fieldMap, $destByKey);
        }

        // Estado por defecto de la corrida + índice key→id para el estado POR evento.
        $defaultStatus = ($statusKey ? EventStatus::where('key', $statusKey)->first() : null)
            ?? EventStatus::where('is_initial', true)->orderBy('sort_order')->first();
        if (! $defaultStatus) {
            throw new \RuntimeException('No hay estados de evento configurados.');
        }
        $defaultStatusId = (int) $defaultStatus->id;
        $statusIdByKey = EventStatus::pluck('id', 'key'); // key => id

        $tasks = $this->fetchTasks($requestId, $sourceType);
        $onlySet = $onlyTaskIds !== null ? array_flip(array_map('intval', $onlyTaskIds)) : null;

        $created = 0;
        $updated = 0;
        $pairs = [];      // todos
        $newPairs = [];   // solo creados (para descargar imágenes sin duplicar)

        foreach ($tasks as $t) {
            $id = (int) $t->Id;
            if ($onlySet !== null && ! isset($onlySet[$id])) {
                continue;
            }
            $deviceId = $map[$id] ?? null;
            if (! $deviceId && $t->device) {
                $key = $this->nz($t->device);
                if (isset($byDid[$key]) && count($byDid[$key]) === 1) {
                    $deviceId = $byDid[$key][0];
                }
            }

            $mapped = $fieldMap ? $this->buildMappedValues($fieldMap, $destByKey, $t) : [];

            $event = Event::firstOrNew(['source_ref' => 'adist3:'.$id]);
            $isNew = ! $event->exists;

            if ($isNew) {
                // Estado elegido para ESTE evento (override por fila); si no, el de la corrida.
                $statusId = $defaultStatusId;
                $rowKey = $statusMap[$id] ?? null;
                if ($rowKey && isset($statusIdByKey[$rowKey])) {
                    $statusId = (int) $statusIdByKey[$rowKey];
                }
                $event->folio = EventFolio::next($site->client);
                $event->fill([
                    'client_id'     => $site->client_id,
                    'site_id'       => $siteId,
                    'system_id'     => $systemId,
                    'event_type_id' => $eventTypeId,
                    'device_id'     => $deviceId,
                    'status_id'     => $statusId,
                    'priority'      => $eventType->default_priority ?: 'media',
                    'description'   => (trim(strip_tags((string) $t->Description)) ?: (string) $t->Title) ?: 'Importado de ADIST',
                    'occurred_at'   => $t->ExecutionDate,
                    'created_by'    => $userId,
                    'field_values'  => $mapped ?: null,
                ]);
                $event->save();

                EventStatusHistory::create([
                    'event_id'       => $event->id,
                    'from_status_id' => null,
                    'to_status_id'   => $statusId,
                    'user_id'        => $userId,
                    'note'           => 'Importado de ADIST',
                    'created_at'     => now(),
                ]);
                $created++;
                $newPairs[] = ['task_id' => $id, 'event_id' => $event->id];
            } else {
                // Backfill: solo mezcla los campos mapeados y el dispositivo (si hay override).
                // Conserva descripción/fecha/estado/folio ya capturados.
                if ($mapped || isset($map[$id])) {
                    $fv = is_array($event->field_values) ? $event->field_values : [];
                    foreach ($mapped as $k => $v) {
                        $fv[$k] = $v;
                    }
                    $event->field_values = $fv;
                    if (isset($map[$id])) {
                        $event->device_id = (int) $map[$id];
                    }
                    $event->save();
                }
                $updated++;
            }

            $pairs[] = ['task_id' => $id, 'event_id' => $event->id];
        }

        return ['created' => $created, 'updated' => $updated, 'pairs' => $pairs, 'new_pairs' => $newPairs, 'supports_images' => true];
    }

    /** Descarga las imágenes de una tarea de ADIST y las mezcla en `events.images`. */
    public function fillEventImages(Event $event, int $taskId): int
    {
        $our = $this->importImages($this->taskImageUrls($taskId));
        if (! $our) {
            return 0;
        }
        $imgs = is_array($event->images) ? $event->images : [];
        $event->update(['images' => array_values(array_unique(array_merge($imgs, $our)))]);

        return count($our);
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
