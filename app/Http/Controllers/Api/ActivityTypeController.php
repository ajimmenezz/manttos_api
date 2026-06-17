<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityTypeField;
use App\Models\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityTypeController extends Controller
{
    private function resolveActivityType(int|string $id): Catalog
    {
        $cat = Catalog::findOrFail($id);
        abort_if($cat->type !== Catalog::TYPE_ACTIVITY_TYPE, 404, 'No es un tipo de actividad válido.');
        return $cat;
    }

    private function resolveSystem(int|string $id): Catalog
    {
        $cat = Catalog::findOrFail($id);
        abort_if($cat->type !== Catalog::TYPE_SYSTEM, 404, 'No es un sistema válido.');
        return $cat;
    }

    /** Sistemas activos con conteo de campos y flag de asociación */
    public function systemsWithFields(int $activityTypeId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);

        $linkedIds = DB::table('activity_type_systems')
            ->where('activity_type_id', $activityTypeId)
            ->pluck('system_id')
            ->all();

        $systems = Catalog::ofType(Catalog::TYPE_SYSTEM)
            ->withCount(['activityFieldsForSystem as fields_count' => function ($q) use ($activityTypeId) {
                $q->where('activity_type_id', $activityTypeId)->where('is_active', true);
            }])
            ->get(['id', 'label', 'is_active'])
            ->map(fn ($s) => [
                'id'           => $s->id,
                'label'        => $s->label,
                'is_active'    => $s->is_active,
                'fields_count' => $s->fields_count,
                'is_linked'    => in_array($s->id, $linkedIds),
            ]);

        return response()->json($systems);
    }

    /** Asociar este tipo de actividad a un sistema */
    public function linkSystem(int $activityTypeId, int $systemId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        DB::table('activity_type_systems')->updateOrInsert(
            ['activity_type_id' => $activityTypeId, 'system_id' => $systemId],
            ['created_at' => now(), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Sistema asociado.']);
    }

    /** Desasociar este tipo de actividad de un sistema */
    public function unlinkSystem(int $activityTypeId, int $systemId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        DB::table('activity_type_systems')
            ->where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->delete();

        return response()->json(['message' => 'Asociación eliminada.']);
    }

    /** Lista de campos para un par (activity_type, system) */
    public function fields(int $activityTypeId, int $systemId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $fields = ActivityTypeField::where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json($fields);
    }

    public function storeField(Request $request, int $activityTypeId, int $systemId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $data = $request->validate([
            'label'        => 'required|string|max:100',
            'field_key'    => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'field_type'   => 'required|in:' . implode(',', ActivityTypeField::FIELD_TYPES),
            'catalog_type' => 'nullable|string|max:60',
            'legend_text'  => 'nullable|string|max:5000|required_if:field_type,leyenda',
            'rules'        => 'nullable|array',
            'visibility'   => 'nullable|array',
            'config'       => 'nullable|array',
            'is_required'  => 'boolean',
            'max_length'   => 'nullable|integer|min:1|max:5000',
        ]);

        // Una leyenda es texto informativo no editable: nunca es obligatoria ni captura valor.
        // Sí puede tener condición de visibilidad (mostrarse solo si aplica), pero la
        // condición de obligatoriedad no tiene sentido para ella.
        if ($data['field_type'] === 'leyenda') {
            $data['is_required']  = false;
            $data['catalog_type'] = null;
            $data['max_length']   = null;
            if (isset($data['visibility']) && is_array($data['visibility'])) {
                unset($data['visibility']['requireWhen']);
            }
        } else {
            $data['legend_text'] = null;
        }

        if (ActivityTypeField::where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->where('field_key', $data['field_key'])
            ->exists()
        ) {
            return response()->json(['message' => 'Ya existe un campo con esa clave para este tipo de actividad y sistema.'], 422);
        }

        $maxOrder = ActivityTypeField::where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->max('sort_order') ?? -1;

        $field = ActivityTypeField::create(array_merge($data, [
            'activity_type_id' => $activityTypeId,
            'system_id'        => $systemId,
            'sort_order'       => $maxOrder + 1,
            'is_active'        => true,
        ]));

        return response()->json(['message' => 'Campo creado.', 'field' => $field], 201);
    }

    public function updateField(Request $request, int $activityTypeId, int $systemId, ActivityTypeField $field): JsonResponse
    {
        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $data = $request->validate([
            'label'        => 'required|string|max:100',
            'field_type'   => 'required|in:' . implode(',', ActivityTypeField::FIELD_TYPES),
            'catalog_type' => 'nullable|string|max:60',
            'legend_text'  => 'nullable|string|max:5000|required_if:field_type,leyenda',
            'rules'        => 'nullable|array',
            'visibility'   => 'nullable|array',
            'config'       => 'nullable|array',
            'is_required'  => 'boolean',
            'max_length'   => 'nullable|integer|min:1|max:5000',
        ]);

        // Una leyenda es texto informativo no editable: nunca es obligatoria ni captura valor.
        // Sí puede tener condición de visibilidad (mostrarse solo si aplica), pero la
        // condición de obligatoriedad no tiene sentido para ella.
        if ($data['field_type'] === 'leyenda') {
            $data['is_required']  = false;
            $data['catalog_type'] = null;
            $data['max_length']   = null;
            if (isset($data['visibility']) && is_array($data['visibility'])) {
                unset($data['visibility']['requireWhen']);
            }
        } else {
            $data['legend_text'] = null;
        }

        $field->update($data);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleField(int $activityTypeId, int $systemId, ActivityTypeField $field): JsonResponse
    {
        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $field->update(['is_active' => ! $field->is_active]);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleBitacora(int $activityTypeId, int $systemId, ActivityTypeField $field): JsonResponse
    {
        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $field->update(['show_in_bitacora' => ! $field->show_in_bitacora]);
        $status = $field->show_in_bitacora ? 'incluido en' : 'excluido de';

        return response()->json(['message' => "Campo {$status} bitácora.", 'field' => $field]);
    }

    public function reorderFields(Request $request, int $activityTypeId, int $systemId): JsonResponse
    {
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $request->validate([
            'field_ids'   => 'required|array',
            'field_ids.*' => 'integer|exists:activity_type_fields,id',
        ]);

        DB::transaction(function () use ($request, $activityTypeId, $systemId) {
            foreach ($request->field_ids as $index => $fieldId) {
                ActivityTypeField::where('id', $fieldId)
                    ->where('activity_type_id', $activityTypeId)
                    ->where('system_id', $systemId)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroyField(int $activityTypeId, int $systemId, ActivityTypeField $field): JsonResponse
    {
        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $field->delete();

        return response()->json(['message' => 'Campo eliminado.']);
    }
}
