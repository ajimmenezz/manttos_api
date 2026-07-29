<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\EventStatus;
use App\Models\EventType;
use App\Models\EventTypeAutomation;
use App\Models\EventTypeField;
use App\Models\EventTypeTransition;
use App\Models\User;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventTypeController extends Controller
{
    private function resolveSystem(int|string $id): Catalog
    {
        $cat = Catalog::findOrFail($id);
        abort_if($cat->type !== Catalog::TYPE_SYSTEM, 404, 'No es un sistema válido.');
        return $cat;
    }

    // ─── CRUD del tipo de evento ──────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $query = EventType::query()->withCount('linkedSystems as systems_count');
        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }
        $types = $query->orderBy('sort_order')->orderBy('label')->get();
        return response()->json($types);
    }

    public function show(EventType $eventType): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        return response()->json($eventType);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $data = $this->validateType($request);
        $data['created_by'] = $request->user()->id;
        $data['sort_order'] = (EventType::max('sort_order') ?? -1) + 1;
        $type = EventType::create($data);
        return response()->json(['message' => 'Tipo de evento creado.', 'event_type' => $type], 201);
    }

    public function update(Request $request, EventType $eventType): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $eventType->update($this->validateType($request));
        return response()->json(['message' => 'Tipo de evento actualizado.', 'event_type' => $eventType]);
    }

    public function toggleStatus(EventType $eventType): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $eventType->update(['is_active' => ! $eventType->is_active]);
        return response()->json(['message' => 'Estado actualizado.', 'event_type' => $eventType]);
    }

    private function validateType(Request $request): array
    {
        return $request->validate([
            'label'            => 'required|string|max:100',
            'nature'           => 'required|in:' . implode(',', EventType::NATURES),
            'color'            => 'nullable|string|max:9',
            'default_priority' => 'required|in:' . implode(',', EventType::PRIORITIES),
        ]);
    }

    // ─── Sistemas asociados (pivot event_type_systems) ────────────

    public function systemsWithFields(EventType $eventType): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $linkedIds = DB::table('event_type_systems')
            ->where('event_type_id', $eventType->id)
            ->pluck('system_id')
            ->all();

        $systems = Catalog::ofType(Catalog::TYPE_SYSTEM)
            ->get(['id', 'label', 'is_active'])
            ->map(function ($s) use ($eventType, $linkedIds) {
                $count = EventTypeField::where('event_type_id', $eventType->id)
                    ->where('system_id', $s->id)
                    ->where('is_active', true)
                    ->count();
                return [
                    'id'           => $s->id,
                    'label'        => $s->label,
                    'is_active'    => $s->is_active,
                    'fields_count' => $count,
                    'is_linked'    => in_array($s->id, $linkedIds),
                ];
            });

        return response()->json($systems);
    }

    public function linkSystem(EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);
        DB::table('event_type_systems')->updateOrInsert(
            ['event_type_id' => $eventType->id, 'system_id' => $systemId],
            ['created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['message' => 'Sistema asociado.']);
    }

    public function unlinkSystem(EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        DB::table('event_type_systems')
            ->where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->delete();
        return response()->json(['message' => 'Asociación eliminada.']);
    }

    // ─── Campos del formulario por (tipo, sistema) ────────────────

    public function fields(EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);
        $fields = EventTypeField::where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        return response()->json($fields);
    }

    public function storeField(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);
        $data = $this->validateField($request);

        if (EventTypeField::where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->where('field_key', $data['field_key'])
            ->exists()
        ) {
            return response()->json(['message' => 'Ya existe un campo con esa clave para este tipo y sistema.'], 422);
        }

        $maxOrder = EventTypeField::where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->max('sort_order') ?? -1;

        $field = EventTypeField::create(array_merge($data, [
            'event_type_id' => $eventType->id,
            'system_id'     => $systemId,
            'sort_order'    => $maxOrder + 1,
            'is_active'     => true,
        ]));

        return response()->json(['message' => 'Campo creado.', 'field' => $field], 201);
    }

    public function updateField(Request $request, EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $data = $this->validateField($request, false);
        $field->update($data);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleField(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $field->update(['is_active' => ! $field->is_active]);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    /** Marca/desmarca el campo para el Reporte de eventos (KPI + filtro). */
    public function toggleReport(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        // Solo tipos explotables pueden encenderse (apagar siempre se permite).
        abort_if(
            ! $field->show_in_report && ! in_array($field->field_type, EventTypeField::REPORTABLE_TYPES, true),
            422,
            'Este tipo de campo no puede marcarse para el reporte.'
        );
        $field->update(['show_in_report' => ! $field->show_in_report]);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    /** Marca/desmarca el campo para imprimirse en la hoja de servicio. */
    public function toggleServiceSheet(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $field->update(['show_in_service_sheet' => ! $field->show_in_service_sheet]);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function reorderFields(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);
        $request->validate([
            'field_ids'   => 'required|array',
            'field_ids.*' => 'integer|exists:event_type_fields,id',
        ]);
        DB::transaction(function () use ($request, $eventType, $systemId) {
            foreach ($request->field_ids as $index => $fieldId) {
                EventTypeField::where('id', $fieldId)
                    ->where('event_type_id', $eventType->id)
                    ->where('system_id', $systemId)
                    ->update(['sort_order' => $index]);
            }
        });
        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroyField(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $field->delete();
        return response()->json(['message' => 'Campo eliminado.']);
    }

    private function validateField(Request $request, bool $withKey = true): array
    {
        $rules = [
            'label'        => 'required|string|max:100',
            'field_type'   => 'required|in:' . implode(',', EventTypeField::FIELD_TYPES),
            'catalog_type' => 'nullable|string|max:60',
            'legend_text'  => 'nullable|string|max:5000|required_if:field_type,leyenda',
            'rules'        => 'nullable|array',
            'visibility'   => 'nullable|array',
            'config'       => 'nullable|array',
            'is_required'  => 'boolean',
            'max_length'   => 'nullable|integer|min:1|max:5000',
        ];
        if ($withKey) {
            $rules['field_key'] = ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'];
        }
        $data = $request->validate($rules);

        // Leyenda: texto informativo, nunca obligatoria ni captura valor.
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
        return $data;
    }

    // ─── Override de transiciones por tipo ────────────────────────

    public function transitions(EventType $eventType): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $rows = EventTypeTransition::where('event_type_id', $eventType->id)
            ->get(['from_status_id', 'to_status_id']);
        return response()->json([
            'has_override' => $rows->isNotEmpty(),
            'transitions'  => $rows,
        ]);
    }

    public function setTransitions(Request $request, EventType $eventType): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $request->validate([
            'transitions'                 => 'present|array',
            'transitions.*.from_status_id' => 'required|integer|exists:event_statuses,id',
            'transitions.*.to_status_id'   => 'required|integer|exists:event_statuses,id',
        ]);

        DB::transaction(function () use ($request, $eventType) {
            EventTypeTransition::where('event_type_id', $eventType->id)->delete();
            foreach ($request->transitions as $t) {
                EventTypeTransition::create([
                    'event_type_id' => $eventType->id,
                    'from_status_id' => $t['from_status_id'],
                    'to_status_id'   => $t['to_status_id'],
                ]);
            }
        });

        return response()->json(['message' => 'Flujo del tipo actualizado.']);
    }

    // ─── Automatizaciones a nivel evento (server-side) ───────────────────

    /** Lista de automatizaciones de un par (event_type, system). */
    public function automations(EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);

        $rows = EventTypeAutomation::with('targetEventType:id,label')
            ->where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json($rows->map(fn ($a) => $this->presentEventAutomation($a)));
    }

    /**
     * Opciones para armar una automatización de evento: catálogo de proveedores de
     * integración con sus acciones, tipos de evento ligados (para seguimiento), estados de
     * evento (para cambio de estado interno) y usuarios asignables (asignación interna).
     */
    public function automationOptions(EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless(auth()->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);

        $providers = collect(app(IntegrationManager::class)->providers())
            ->map(fn ($p, $key) => [
                'key'     => $key,
                'label'   => $p->label(),
                'actions' => $p->supportedActions(),
            ])->values();

        $eventTypeIds = DB::table('event_type_systems')->where('system_id', $systemId)->pluck('event_type_id');
        $eventTypes = EventType::whereIn('id', $eventTypeIds)->where('is_active', true)->orderBy('label')->get(['id', 'label']);

        $statuses = EventStatus::where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'label']);

        // Usuarios asignables (ingenieros/técnicos): para la acción interna de asignación.
        $assignees = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['ingeniero', 'tecnico']))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'providers'   => $providers,
            'event_types' => $eventTypes,
            'statuses'    => $statuses,
            'assignees'   => $assignees,
            'events'      => EventTypeAutomation::EVENTS,
            'action_kinds' => EventTypeAutomation::ACTION_KINDS,
            'internal_actions' => EventTypeAutomation::INTERNAL_ACTIONS,
        ]);
    }

    public function storeAutomation(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);

        $data = $this->validateEventAutomation($request);
        $maxOrder = EventTypeAutomation::where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)->max('sort_order') ?? -1;

        $automation = EventTypeAutomation::create(array_merge($data, [
            'event_type_id' => $eventType->id,
            'system_id'     => $systemId,
            'sort_order'    => $maxOrder + 1,
        ]));

        return response()->json($this->presentEventAutomation($automation->fresh('targetEventType:id,label')), 201);
    }

    public function updateAutomation(Request $request, EventType $eventType, int $systemId, EventTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->assertEventAutomationBelongs($automation, $eventType->id, $systemId);

        $automation->update($this->validateEventAutomation($request));

        return response()->json($this->presentEventAutomation($automation->fresh('targetEventType:id,label')));
    }

    public function toggleAutomation(Request $request, EventType $eventType, int $systemId, EventTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->assertEventAutomationBelongs($automation, $eventType->id, $systemId);

        $automation->update(['is_active' => ! $automation->is_active]);

        return response()->json(['is_active' => $automation->is_active]);
    }

    public function reorderAutomations(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->resolveSystem($systemId);

        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        DB::transaction(function () use ($ids, $eventType, $systemId) {
            foreach ($ids as $index => $id) {
                EventTypeAutomation::where('id', $id)
                    ->where('event_type_id', $eventType->id)->where('system_id', $systemId)
                    ->update(['sort_order' => $index]);
            }
        });

        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroyAutomation(Request $request, EventType $eventType, int $systemId, EventTypeAutomation $automation): JsonResponse
    {
        abort_unless($request->user()->can('event-config.manage'), 403, 'No autorizado para esta acción.');
        $this->assertEventAutomationBelongs($automation, $eventType->id, $systemId);

        $automation->delete();

        return response()->json(['message' => 'Automatización eliminada.']);
    }

    private function assertEventAutomationBelongs(EventTypeAutomation $automation, int $eventTypeId, int $systemId): void
    {
        abort_unless(
            $automation->event_type_id === $eventTypeId && $automation->system_id === $systemId,
            404
        );
    }

    private function validateEventAutomation(Request $request): array
    {
        return $request->validate([
            'name'            => 'required|string|max:120',
            'is_active'       => 'boolean',
            'event'           => 'required|in:' . implode(',', EventTypeAutomation::EVENTS),
            'status_key'      => 'nullable|string|max:120',
            'trigger'         => 'nullable|array',
            'action_kind'     => 'required|in:' . implode(',', EventTypeAutomation::ACTION_KINDS),
            'provider'        => 'nullable|string|in:odoo,jira|required_if:action_kind,integration,query',
            'action'          => 'nullable|string|max:60|required_if:action_kind,integration,query',
            'params_map'                 => 'nullable|array',
            'params_map.*.param_key'     => 'required|string|max:60',
            'params_map.*.mode'          => 'required|in:constant,field',
            'params_map.*.value'         => 'nullable',
            'params_map.*.source'        => 'nullable|in:form,device,event',
            'params_map.*.source_field_key' => 'nullable|string|max:60',
            'lines_map'       => 'nullable|array',
            'result_target'   => 'nullable|string|max:60',
            'internal_action' => 'nullable|in:' . implode(',', EventTypeAutomation::INTERNAL_ACTIONS) . '|required_if:action_kind,internal',
            'internal_config' => 'nullable|array',
            'target_event_type_id' => 'nullable|integer|exists:event_types,id|required_if:action_kind,event',
            'prefill'                    => 'nullable|array',
            'prefill.*.target_field_key' => 'required|string|max:60',
            'prefill.*.mode'             => 'required|in:constant,copy',
            'prefill.*.value'            => 'nullable',
            'prefill.*.source'           => 'nullable|in:form,device',
            'prefill.*.source_field_key' => 'nullable|string|max:60',
            'run_once'        => 'boolean',
        ]);
    }

    private function presentEventAutomation(EventTypeAutomation $a): array
    {
        return [
            'id'                   => $a->id,
            'name'                 => $a->name,
            'is_active'            => $a->is_active,
            'sort_order'           => $a->sort_order,
            'event'                => $a->event,
            'status_key'           => $a->status_key,
            'trigger'              => $a->trigger,
            'action_kind'          => $a->action_kind,
            'provider'             => $a->provider,
            'action'               => $a->action,
            'params_map'           => $a->params_map ?? [],
            'lines_map'            => $a->lines_map ?? [],
            'result_target'        => $a->result_target,
            'internal_action'      => $a->internal_action,
            'internal_config'      => $a->internal_config ?? [],
            'target_event_type_id' => $a->target_event_type_id,
            'target_label'         => $a->targetEventType->label ?? null,
            'prefill'              => $a->prefill ?? [],
            'run_once'             => $a->run_once,
        ];
    }
}
