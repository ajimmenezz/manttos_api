<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\SystemField;
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
            'device_type_ids'   => 'required|array',
            'device_type_ids.*' => 'exists:catalogs,id',
        ]);

        // Verificar que todos sean de tipo device_type
        $invalid = Catalog::whereIn('id', $request->device_type_ids)
            ->where('type', '!=', Catalog::TYPE_DEVICE_TYPE)
            ->exists();

        abort_if($invalid, 422, 'Todos los IDs deben ser tipos de dispositivo.');

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
            ->get(['catalogs.id', 'catalogs.label']);

        // Si el sistema no tiene tipos asignados, devuelve todos los activos
        if ($types->isEmpty()) {
            $types = Catalog::ofType(Catalog::TYPE_DEVICE_TYPE)->get(['id', 'label']);
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

        $deviceType->systems()->sync($request->system_ids);

        return response()->json([
            'message' => 'Sistemas actualizados.',
            'systems' => $deviceType->systems()->orderBy('label')->get(['catalogs.id', 'catalogs.label']),
        ]);
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
            'field_type'        => 'required|in:text,number,date,boolean,list',
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'sort_order'        => 'nullable|integer|min:0',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema. Usa una etiqueta diferente.');

        $exists = $system->fields()->whereNull('client_id')->where('field_key', $data['field_key'])->exists();
        abort_if($exists, 422, "Ya existe un campo con la clave '{$data['field_key']}' en este sistema.");

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
            'field_type'        => 'required|in:text,number,date,boolean,list',
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'sort_order'        => 'nullable|integer|min:0',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema. Usa una etiqueta diferente.');

        $field->update($data);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
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
