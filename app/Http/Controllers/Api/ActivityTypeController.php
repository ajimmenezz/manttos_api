<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityTypeAutomation;
use App\Models\ActivityTypeField;
use App\Models\Catalog;
use App\Models\EventType;
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
        abort_unless(auth()->user()->can('catalogs.view'), 403, 'No autorizado para esta acción.');

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
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $field->update(['is_active' => ! $field->is_active]);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleBitacora(int $activityTypeId, int $systemId, ActivityTypeField $field): JsonResponse
    {
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

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
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');

        abort_unless(
            $field->activity_type_id === $activityTypeId && $field->system_id === $systemId,
            404
        );

        $field->delete();

        return response()->json(['message' => 'Campo eliminado.']);
    }

    // ─── Automatizaciones a nivel actividad ──────────────────────────────

    /** Lista de automatizaciones de un par (activity_type, system). */
    public function automations(int $activityTypeId, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('catalogs.view'), 403, 'No autorizado para esta acción.');
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $rows = ActivityTypeAutomation::with(['targetActivityType:id,label', 'targetEventType:id,label'])
            ->where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json($rows->map(fn ($a) => $this->presentAutomation($a)));
    }

    /**
     * Automatizaciones ACTIVAS de un par (para el runtime de captura en web y móvil).
     * Gateado por quien documenta actividades (o config), no por catalogs.view, para que
     * el ingeniero pueda evaluarlas al guardar. El móvil las cachea en el sync (offline).
     */
    public function activeAutomations(int $activityTypeId, int $systemId): JsonResponse
    {
        $u = auth()->user();
        abort_unless($u->can('maintenances.record-activity') || $u->can('catalogs.view'), 403, 'No autorizado para esta acción.');
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $rows = ActivityTypeAutomation::with(['targetActivityType:id,label', 'targetEventType:id,label'])
            ->where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json($rows->map(fn ($a) => $this->presentAutomation($a)));
    }

    /**
     * Opciones para armar una automatización: destinos posibles (tipos de actividad y
     * tipos de evento ligados a este sistema). Los campos del origen para las condiciones
     * ya los tiene el cliente; los del destino se piden al elegirlo (endpoints existentes).
     */
    public function automationOptions(int $activityTypeId, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $activityTypeIds = DB::table('activity_type_systems')
            ->where('system_id', $systemId)->pluck('activity_type_id');
        $activityTypes = Catalog::whereIn('id', $activityTypeIds)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label']);

        $eventTypeIds = DB::table('event_type_systems')
            ->where('system_id', $systemId)->pluck('event_type_id');
        $eventTypes = EventType::whereIn('id', $eventTypeIds)
            ->where('is_active', true)->orderBy('label')->get(['id', 'label']);

        return response()->json([
            'activity_types' => $activityTypes,
            'event_types'    => $eventTypes,
        ]);
    }

    public function storeAutomation(Request $request, int $activityTypeId, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $data = $this->validateAutomation($request);

        $maxOrder = ActivityTypeAutomation::where('activity_type_id', $activityTypeId)
            ->where('system_id', $systemId)->max('sort_order') ?? -1;

        $automation = ActivityTypeAutomation::create(array_merge($data, [
            'activity_type_id' => $activityTypeId,
            'system_id'        => $systemId,
            'sort_order'       => $maxOrder + 1,
        ]));

        return response()->json($this->presentAutomation($automation->fresh(['targetActivityType:id,label', 'targetEventType:id,label'])), 201);
    }

    public function updateAutomation(Request $request, int $activityTypeId, int $systemId, ActivityTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->assertAutomationBelongs($automation, $activityTypeId, $systemId);

        $automation->update($this->validateAutomation($request));

        return response()->json($this->presentAutomation($automation->fresh(['targetActivityType:id,label', 'targetEventType:id,label'])));
    }

    public function toggleAutomation(Request $request, int $activityTypeId, int $systemId, ActivityTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->assertAutomationBelongs($automation, $activityTypeId, $systemId);

        $automation->update(['is_active' => ! $automation->is_active]);

        return response()->json(['is_active' => $automation->is_active]);
    }

    public function reorderAutomations(Request $request, int $activityTypeId, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->resolveActivityType($activityTypeId);
        $this->resolveSystem($systemId);

        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        DB::transaction(function () use ($ids, $activityTypeId, $systemId) {
            foreach ($ids as $index => $id) {
                ActivityTypeAutomation::where('id', $id)
                    ->where('activity_type_id', $activityTypeId)
                    ->where('system_id', $systemId)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroyAutomation(Request $request, int $activityTypeId, int $systemId, ActivityTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('activity-types.configure'), 403, 'No autorizado para esta acción.');
        $this->assertAutomationBelongs($automation, $activityTypeId, $systemId);

        $automation->delete();

        return response()->json(['message' => 'Automatización eliminada.']);
    }

    private function assertAutomationBelongs(ActivityTypeAutomation $automation, int $activityTypeId, int $systemId): void
    {
        abort_unless(
            $automation->activity_type_id === $activityTypeId && $automation->system_id === $systemId,
            404
        );
    }

    private function validateAutomation(Request $request): array
    {
        return $request->validate([
            'name'                     => 'required|string|max:120',
            'is_active'                => 'boolean',
            'trigger'                  => 'nullable|array',
            'action_type'              => 'required|in:activity,event',
            'target_activity_type_id'  => 'nullable|integer|exists:catalogs,id|required_if:action_type,activity',
            'target_event_type_id'     => 'nullable|integer|exists:event_types,id|required_if:action_type,event',
            'prefill'                  => 'nullable|array',
            'prefill.*.target_field_key' => 'required|string|max:60',
            'prefill.*.mode'           => 'required|in:constant,copy',
            'prefill.*.value'          => 'nullable',
            'prefill.*.source'         => 'nullable|in:form,device',
            'prefill.*.source_field_key' => 'nullable|string|max:60',
        ]);
    }

    private function presentAutomation(ActivityTypeAutomation $a): array
    {
        return [
            'id'                      => $a->id,
            'name'                    => $a->name,
            'is_active'               => $a->is_active,
            'sort_order'              => $a->sort_order,
            'trigger'                 => $a->trigger,
            'action_type'             => $a->action_type,
            'target_activity_type_id' => $a->target_activity_type_id,
            'target_event_type_id'    => $a->target_event_type_id,
            'target_label'            => $a->action_type === 'event'
                ? ($a->targetEventType->label ?? null)
                : ($a->targetActivityType->label ?? null),
            'prefill'                 => $a->prefill ?? [],
        ];
    }
}
