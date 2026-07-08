<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Device;
use App\Models\MaintenanceFrequency;
use App\Models\SystemField;
use App\Models\TaskDuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona las configuraciones de un sistema:
 *   - tipos de dispositivo asignados
 *   - campos de plantilla
 */
class SystemController extends Controller
{
    private function resolveSystem(int|string $id): Catalog
    {
        $system = Catalog::findOrFail($id);
        abort_if($system->type !== Catalog::TYPE_SYSTEM, 404, 'No es un sistema válido.');
        return $system;
    }

    // ── Tipos de dispositivo ──────────────────────────────────────────────────

    public function deviceTypes(int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        return response()->json(
            $system->deviceTypes()->orderBy('label')->get(['catalogs.id', 'catalogs.label', 'catalogs.is_active'])
        );
    }

    /** Asigna (sync) un conjunto de tipos de dispositivo al sistema */
    public function syncDeviceTypes(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $request->validate([
            // 'present' (no 'required') para permitir un arreglo vacío: desasociar
            // todos los tipos es válido (regla de negocio: sin tipos → aparecen todos).
            'device_type_ids'   => 'present|array',
            'device_type_ids.*' => 'exists:catalogs,id',
        ]);

        // Verificar que todos sean de tipo device_type
        $invalid = Catalog::whereIn('id', $request->device_type_ids)
            ->where('type', '!=', Catalog::TYPE_DEVICE_TYPE)
            ->exists();

        abort_if($invalid, 422, 'Todos los IDs deben ser tipos de dispositivo.');

        // Integridad: no permitir desasociar un tipo que aún está en uso por algún
        // dispositivo de los directorios de este sistema. Hay que eliminar o
        // re-tipificar esos dispositivos antes de quitar la asociación.
        $currentIds  = $system->deviceTypes()->get(['catalogs.id'])->pluck('id')->all();
        $newIds      = array_map('intval', $request->device_type_ids);
        $removingIds = array_diff($currentIds, $newIds);

        if (! empty($removingIds)) {
            $removingLabels = Catalog::whereIn('id', $removingIds)->pluck('label')->all();

            $conflicts = Device::whereIn('device_type', $removingLabels)
                ->whereHas('directory', fn ($q) => $q->where('catalog_id', $system->id))
                ->with(['directory:id,name,site_id,catalog_id', 'directory.site:id,name'])
                ->get(['id', 'directory_id', 'device_type']);

            if ($conflicts->isNotEmpty()) {
                $details = $conflicts
                    ->groupBy(fn ($d) => $d->directory_id . '|' . $d->device_type)
                    ->map(function ($group) {
                        $first   = $group->first();
                        $dir     = $first->directory;
                        $dirName = $dir?->display_name ?? 'directorio';
                        $site    = $dir?->site?->name;
                        $where   = $site ? "{$dirName} ({$site})" : $dirName;
                        return "{$group->count()} dispositivo(s) de tipo «{$first->device_type}» en el directorio \"{$where}\"";
                    })
                    ->values();

                return response()->json([
                    'message'   => 'No se puede desasociar: existe ' . $details->implode('; ')
                        . '. Elimina o re-tipifica esos dispositivos antes de quitar la asociación.',
                    'conflicts' => $details,
                ], 422);
            }
        }

        $system->deviceTypes()->sync($request->device_type_ids);

        return response()->json([
            'message'      => 'Tipos de dispositivo actualizados.',
            'device_types' => $system->deviceTypes()->orderBy('label')->get(['catalogs.id', 'catalogs.label']),
        ]);
    }

    /** Lista activa de tipos de dispositivo de un sistema (para selects al registrar dispositivos) */
    public function activeDeviceTypes(int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $types = $system->deviceTypes()
            ->where('catalogs.is_active', true)
            ->orderBy('catalogs.label')
            ->get(['catalogs.id', 'catalogs.label', 'catalogs.nomenclatura']);

        // Si el sistema no tiene tipos asignados, devuelve todos los activos
        if ($types->isEmpty()) {
            $types = Catalog::ofType(Catalog::TYPE_DEVICE_TYPE)->get(['id', 'label', 'nomenclatura']);
        }

        return response()->json($types);
    }

    // ── API inversa: desde un tipo de dispositivo ────────────────────────────

    /** Sistemas asignados a un tipo de dispositivo */
    public function deviceTypeSystems(int $catalogId): JsonResponse
    {
        $deviceType = Catalog::findOrFail($catalogId);
        abort_if($deviceType->type !== Catalog::TYPE_DEVICE_TYPE, 404, 'No es un tipo de dispositivo.');

        return response()->json(
            $deviceType->systems()->orderBy('label')->get(['catalogs.id', 'catalogs.label'])
        );
    }

    /** Fusiona uno o más tipos de dispositivo en el tipo canónico */
    public function mergeDeviceTypes(Request $request, int $canonicalId): JsonResponse
    {
        $canonical = Catalog::findOrFail($canonicalId);
        abort_if($canonical->type !== Catalog::TYPE_DEVICE_TYPE, 404, 'No es un tipo de dispositivo.');

        $request->validate([
            'source_ids'   => 'required|array|min:1',
            'source_ids.*' => 'integer|exists:catalogs,id',
        ]);

        $sourceIds = collect($request->source_ids)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id !== $canonicalId)
            ->unique()
            ->values()
            ->toArray();

        abort_if(empty($sourceIds), 422, 'Debes seleccionar al menos un tipo diferente al canónico.');

        $sources = Catalog::whereIn('id', $sourceIds)
            ->where('type', Catalog::TYPE_DEVICE_TYPE)
            ->get();

        abort_if($sources->count() !== count($sourceIds), 422, 'Algunos IDs no son tipos de dispositivo válidos.');

        $affectedDevices = DB::transaction(function () use ($canonical, $sources, $sourceIds) {
            $sourceLabels = $sources->pluck('label')->toArray();

            $count = DB::table('devices')->whereIn('device_type', $sourceLabels)->count();

            // Actualizar columna dedicada
            DB::table('devices')
                ->whereIn('device_type', $sourceLabels)
                ->update(['device_type' => $canonical->label]);

            // Actualizar custom_fields JSONB y device_field_values para campos de tipo device_type
            $deviceTypeFieldKeys = \App\Models\SystemField::where('catalog_type', 'device_type')
                ->pluck('field_key')
                ->unique()
                ->toArray();

            foreach ($deviceTypeFieldKeys as $fieldKey) {
                foreach ($sourceLabels as $sourceLabel) {
                    DB::update(
                        "UPDATE devices SET custom_fields = jsonb_set(custom_fields, ARRAY[?], to_jsonb(?::text)) WHERE custom_fields->>? = ?",
                        [$fieldKey, $canonical->label, $fieldKey, $sourceLabel]
                    );
                }
            }

            // Actualizar device_field_values (tabla normalizada)
            $deviceTypeFieldIds = \App\Models\SystemField::where('catalog_type', 'device_type')
                ->pluck('id')
                ->toArray();

            if (!empty($deviceTypeFieldIds)) {
                DB::table('device_field_values')
                    ->whereIn('system_field_id', $deviceTypeFieldIds)
                    ->whereIn('value_text', $sourceLabels)
                    ->update(['value_text' => $canonical->label]);
            }

            $canonicalSystemIds = DB::table('system_device_types')
                ->where('device_type_catalog_id', $canonical->id)
                ->pluck('system_catalog_id')
                ->toArray();

            $sourceSystemIds = DB::table('system_device_types')
                ->whereIn('device_type_catalog_id', $sourceIds)
                ->pluck('system_catalog_id')
                ->toArray();

            foreach (array_unique(array_merge($canonicalSystemIds, $sourceSystemIds)) as $systemId) {
                DB::table('system_device_types')->updateOrInsert(
                    ['system_catalog_id' => $systemId, 'device_type_catalog_id' => $canonical->id],
                    []
                );
            }

            DB::table('system_device_types')->whereIn('device_type_catalog_id', $sourceIds)->delete();
            Catalog::whereIn('id', $sourceIds)->delete();

            return $count;
        });

        return response()->json([
            'message'          => 'Tipos fusionados correctamente.',
            'affected_devices' => $affectedDevices,
        ]);
    }

    /** Sincroniza los sistemas asignados a un tipo de dispositivo */
    public function syncDeviceTypeSystems(Request $request, int $catalogId): JsonResponse
    {
        $deviceType = Catalog::findOrFail($catalogId);
        abort_if($deviceType->type !== Catalog::TYPE_DEVICE_TYPE, 404, 'No es un tipo de dispositivo.');

        $request->validate([
            'system_ids'   => 'present|array',
            'system_ids.*' => 'exists:catalogs,id',
        ]);

        // Integridad (dirección inversa): no desasociar un sistema si hay dispositivos
        // de este tipo en los directorios de ese sistema.
        $currentSystemIds  = $deviceType->systems()->get(['catalogs.id'])->pluck('id')->all();
        $newSystemIds      = array_map('intval', $request->system_ids);
        $removingSystemIds = array_diff($currentSystemIds, $newSystemIds);

        if (! empty($removingSystemIds)) {
            $conflicts = Device::where('device_type', $deviceType->label)
                ->whereHas('directory', fn ($q) => $q->whereIn('catalog_id', $removingSystemIds))
                ->with(['directory:id,name,site_id,catalog_id', 'directory.site:id,name'])
                ->get(['id', 'directory_id', 'device_type']);

            if ($conflicts->isNotEmpty()) {
                $details = $conflicts
                    ->groupBy('directory_id')
                    ->map(function ($group) {
                        $dir     = $group->first()->directory;
                        $dirName = $dir?->display_name ?? 'directorio';
                        $site    = $dir?->site?->name;
                        $where   = $site ? "{$dirName} ({$site})" : $dirName;
                        return "{$group->count()} dispositivo(s) en el directorio \"{$where}\"";
                    })
                    ->values();

                return response()->json([
                    'message'   => 'No se puede desasociar: existe ' . $details->implode('; ')
                        . '. Elimina o re-tipifica esos dispositivos antes de quitar la asociación.',
                    'conflicts' => $details,
                ], 422);
            }
        }

        $deviceType->systems()->sync($request->system_ids);

        return response()->json([
            'message' => 'Sistemas actualizados.',
            'systems' => $deviceType->systems()->orderBy('label')->get(['catalogs.id', 'catalogs.label']),
        ]);
    }

    // ── Tipos de actividad y frecuencias de mantenimiento ─────────────────────

    /** Tipos de actividad asociados al sistema (activos). */
    public function activityTypes(int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        return response()->json(
            $system->activityTypes()
                ->where('catalogs.is_active', true)
                ->orderBy('catalogs.label')
                ->get(['catalogs.id', 'catalogs.label'])
        );
    }

    /** Frecuencias de mantenimiento definidas para el sistema. */
    public function frequencies(int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        return response()->json(
            MaintenanceFrequency::where('system_id', $system->id)
                ->get(['device_type_id', 'activity_type_id', 'period_value', 'period_unit'])
        );
    }

    /** Reemplaza (sync) las frecuencias del sistema con la matriz enviada. */
    public function syncFrequencies(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $data = $request->validate([
            'frequencies'                    => 'present|array',
            'frequencies.*.device_type_id'   => 'required|integer|exists:catalogs,id',
            'frequencies.*.activity_type_id' => 'required|integer|exists:catalogs,id',
            // 'as_needed' (cada que sea necesario) no lleva valor; el resto sí.
            'frequencies.*.period_unit'      => 'required|in:' . implode(',', MaintenanceFrequency::UNITS),
            'frequencies.*.period_value'     => 'nullable|integer|min:1|max:9999|required_unless:frequencies.*.period_unit,as_needed',
        ]);

        // Solo se aceptan combinaciones válidas: el tipo de dispositivo debe estar
        // asociado al sistema y el tipo de actividad debe estar enlazado al sistema.
        $validDeviceTypes  = $system->deviceTypes()->pluck('catalogs.id')->all();
        $validActivityTypes = $system->activityTypes()->pluck('catalogs.id')->all();

        DB::transaction(function () use ($system, $data, $validDeviceTypes, $validActivityTypes) {
            MaintenanceFrequency::where('system_id', $system->id)->delete();

            foreach ($data['frequencies'] as $row) {
                if (! in_array((int) $row['device_type_id'], $validDeviceTypes, true)) continue;
                if (! in_array((int) $row['activity_type_id'], $validActivityTypes, true)) continue;

                MaintenanceFrequency::create([
                    'system_id'        => $system->id,
                    'device_type_id'   => $row['device_type_id'],
                    'activity_type_id' => $row['activity_type_id'],
                    'period_value'     => $row['period_unit'] === 'as_needed' ? null : $row['period_value'],
                    'period_unit'      => $row['period_unit'],
                ]);
            }
        });

        return response()->json(['message' => 'Frecuencias de mantenimiento actualizadas.']);
    }

    /** Tiempos estándar por tarea (minutos) definidos para el sistema. */
    public function taskDurations(int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        return response()->json(
            TaskDuration::where('system_id', $system->id)
                ->get(['device_type_id', 'activity_type_id', 'minutes'])
        );
    }

    /** Reemplaza (sync) los tiempos por tarea del sistema con la matriz enviada. */
    public function syncTaskDurations(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $data = $request->validate([
            'durations'                    => 'present|array',
            'durations.*.device_type_id'   => 'required|integer|exists:catalogs,id',
            'durations.*.activity_type_id' => 'required|integer|exists:catalogs,id',
            'durations.*.minutes'          => 'required|integer|min:1|max:100000',
        ]);

        $validDeviceTypes   = $system->deviceTypes()->pluck('catalogs.id')->all();
        $validActivityTypes = $system->activityTypes()->pluck('catalogs.id')->all();

        DB::transaction(function () use ($system, $data, $validDeviceTypes, $validActivityTypes) {
            TaskDuration::where('system_id', $system->id)->delete();

            foreach ($data['durations'] as $row) {
                if (! in_array((int) $row['device_type_id'], $validDeviceTypes, true)) continue;
                if (! in_array((int) $row['activity_type_id'], $validActivityTypes, true)) continue;

                TaskDuration::create([
                    'system_id'        => $system->id,
                    'device_type_id'   => $row['device_type_id'],
                    'activity_type_id' => $row['activity_type_id'],
                    'minutes'          => $row['minutes'],
                ]);
            }
        });

        return response()->json(['message' => 'Tiempos por tarea actualizados.']);
    }

    // ── Campos de plantilla ───────────────────────────────────────────────────

    public function fields(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $query = $system->fields();

        if ($request->filled('client_id')) {
            // Formulario de dispositivos: campos base + campos extra del cliente, solo activos
            $clientId = (int) $request->client_id;
            $query->where(function ($q) use ($clientId) {
                $q->whereNull('client_id')->orWhere('client_id', $clientId);
            })->where('is_active', true);
        } else {
            // Gestión de plantilla base: solo campos base (activos e inactivos)
            $query->whereNull('client_id');
        }

        return response()->json($query->orderBy('sort_order')->orderBy('label')->get());
    }

    public function storeField(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $data = $request->validate([
            'label'             => 'required|string|max:100',
            'field_key'         => 'required|string|max:60|regex:/^[a-z][a-z0-9_]*$/',
            'field_type'        => 'required|in:' . implode(',', SystemField::FIELD_TYPES),
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'config'            => 'nullable|array',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'sort_order'        => 'nullable|integer|min:0',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema. Usa una etiqueta diferente.');

        $exists = $system->fields()->whereNull('client_id')->where('field_key', $data['field_key'])->exists();
        abort_if($exists, 422, "Ya existe un campo con la clave '{$data['field_key']}' en este sistema.");

        if ($data['field_type'] === 'did') {
            abort_if(
                $system->fields()->whereNull('client_id')->where('field_type', 'did')->exists(),
                422, 'Este sistema ya tiene un campo DID. Solo puede haber uno por sistema.'
            );
            $this->validateDidConfig($system, $data['config'] ?? []);
        } else {
            $data['config'] = null;
        }

        $field = $system->fields()->create([
            ...$data,
            'is_active'   => true,
            'sort_order'  => $data['sort_order'] ?? ($system->fields()->max('sort_order') + 1),
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['message' => 'Campo creado.', 'field' => $field], 201);
    }

    public function updateField(Request $request, int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $data = $request->validate([
            'label'             => 'required|string|max:100',
            'field_type'        => 'required|in:' . implode(',', SystemField::FIELD_TYPES),
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'config'            => 'nullable|array',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'sort_order'        => 'nullable|integer|min:0',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema. Usa una etiqueta diferente.');

        if ($data['field_type'] === 'did') {
            abort_if(
                $system->fields()->whereNull('client_id')->where('field_type', 'did')->where('id', '!=', $field->id)->exists(),
                422, 'Este sistema ya tiene un campo DID. Solo puede haber uno por sistema.'
            );
            $this->validateDidConfig($system, $data['config'] ?? []);
        } else {
            $data['config'] = null;
        }

        // No permitir quitar la obligatoriedad si el campo se usa en el patrón de un DID.
        if (array_key_exists('is_required', $data) && ! $data['is_required']
            && $this->patternReferencedKeys($system)->contains($field->field_key)
        ) {
            abort(422, "No puedes quitar la obligatoriedad de «{$field->label}»: se usa en el patrón de un DID. Quítalo del patrón primero.");
        }

        $field->update($data);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    /** Field keys referenciados por el patrón de algún campo DID del sistema. */
    private function patternReferencedKeys(Catalog $system, ?int $excludeFieldId = null)
    {
        return $system->fields()->whereNull('client_id')
            ->where('field_type', 'did')
            ->when($excludeFieldId, fn ($q) => $q->where('id', '!=', $excludeFieldId))
            ->get()
            ->flatMap(fn ($f) => collect($f->config['pattern'] ?? [])
                ->where('kind', 'field')->pluck('field_key'))
            ->filter()->unique()->values();
    }

    /** Valida la configuración de un campo DID (modo y, si patrón, referencias obligatorias). */
    private function validateDidConfig(Catalog $system, array $config): void
    {
        $mode = $config['did_mode'] ?? null;
        abort_unless(in_array($mode, ['number', 'text', 'pattern'], true), 422, 'Modo de DID inválido.');

        if ($mode === 'pattern') {
            $tokens = $config['pattern'] ?? [];
            abort_if(empty($tokens), 422, 'El patrón del DID no puede estar vacío.');

            $fieldKeys = collect($tokens)->where('kind', 'field')->pluck('field_key')->filter()->unique();
            if ($fieldKeys->isNotEmpty()) {
                $required = $system->fields()->whereNull('client_id')
                    ->whereIn('field_key', $fieldKeys)->where('is_required', true)
                    ->pluck('field_key');
                $missing = $fieldKeys->diff($required);
                abort_if($missing->isNotEmpty(), 422,
                    'El patrón solo puede usar campos obligatorios. No son obligatorios: ' . $missing->implode(', '));
            }
        }
    }

    public function toggleField(int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $field->update(['is_active' => ! $field->is_active]);
        $status = $field->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Campo {$status}.", 'field' => $field]);
    }

    public function toggleDashboard(int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $field->update(['show_in_dashboard' => ! $field->show_in_dashboard]);
        $status = $field->show_in_dashboard ? 'incluido en' : 'excluido del';

        return response()->json(['message' => "Campo {$status} dashboard.", 'field' => $field]);
    }

    public function toggleBitacora(int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $field->update(['show_in_bitacora' => ! $field->show_in_bitacora]);
        $status = $field->show_in_bitacora ? 'incluido en' : 'excluido de';

        return response()->json(['message' => "Campo {$status} bitácora.", 'field' => $field]);
    }

    /** Marca/desmarca el campo del directorio como KPI del Reporte de eventos. */
    public function toggleEventReport(int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $field->update(['show_in_event_report' => ! $field->show_in_event_report]);
        $status = $field->show_in_event_report ? 'incluido en' : 'excluido del';

        return response()->json(['message' => "Campo {$status} reporte de eventos.", 'field' => $field]);
    }

    public function fieldImpact(int $id, SystemField $field): JsonResponse
    {
        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        $affectedDevices = DB::table('device_field_values')
            ->where('system_field_id', $field->id)
            ->distinct('device_id')
            ->count('device_id');

        return response()->json(['affected_devices' => $affectedDevices]);
    }

    public function destroyField(Request $request, int $id, SystemField $field): JsonResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403, 'Solo el superadmin puede eliminar campos de plantilla.');

        $system = $this->resolveSystem($id);
        abort_unless($field->catalog_id === $system->id, 404);

        abort_if(
            $this->patternReferencedKeys($system, $field->id)->contains($field->field_key),
            422, "No puedes eliminar «{$field->label}»: se usa en el patrón de un DID. Quítalo del patrón primero."
        );

        $fieldKey = $field->field_key;

        $deleted = DB::transaction(function () use ($field, $fieldKey, $system) {
            $affected = DB::table('device_field_values')
                ->where('system_field_id', $field->id)
                ->distinct('device_id')
                ->count('device_id');

            // Elimina del índice tipado
            DB::table('device_field_values')->where('system_field_id', $field->id)->delete();

            // Limpia el campo del JSONB en todos los dispositivos del sistema
            // Usa el operador PostgreSQL '-' para eliminar la clave del objeto JSON
            DB::table('devices')
                ->whereIn('directory_id', function ($q) use ($system) {
                    $q->select('id')->from('directories')->where('catalog_id', $system->id);
                })
                ->whereNotNull('custom_fields')
                ->update(['custom_fields' => DB::raw("custom_fields - '{$fieldKey}'")]);

            $field->delete();

            return $affected;
        });

        return response()->json([
            'message'          => "Campo '{$field->label}' eliminado correctamente.",
            'affected_devices' => $deleted,
        ]);
    }

    public function reorderFields(Request $request, int $id): JsonResponse
    {
        $system = $this->resolveSystem($id);

        $request->validate([
            'field_ids'   => 'required|array',
            'field_ids.*' => 'integer|exists:system_fields,id',
        ]);

        foreach ($request->field_ids as $order => $fieldId) {
            SystemField::where('id', $fieldId)
                ->where('catalog_id', $system->id)
                ->update(['sort_order' => $order]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }
}
