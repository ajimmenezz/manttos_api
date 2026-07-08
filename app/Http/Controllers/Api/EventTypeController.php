<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\EventType;
use App\Models\EventTypeField;
use App\Models\EventTypeTransition;
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
        $query = EventType::query()->withCount('linkedSystems as systems_count');
        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }
        $types = $query->orderBy('sort_order')->orderBy('label')->get();
        return response()->json($types);
    }

    public function show(EventType $eventType): JsonResponse
    {
        return response()->json($eventType);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateType($request);
        $data['created_by'] = $request->user()->id;
        $data['sort_order'] = (EventType::max('sort_order') ?? -1) + 1;
        $type = EventType::create($data);
        return response()->json(['message' => 'Tipo de evento creado.', 'event_type' => $type], 201);
    }

    public function update(Request $request, EventType $eventType): JsonResponse
    {
        $eventType->update($this->validateType($request));
        return response()->json(['message' => 'Tipo de evento actualizado.', 'event_type' => $eventType]);
    }

    public function toggleStatus(EventType $eventType): JsonResponse
    {
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
        $this->resolveSystem($systemId);
        DB::table('event_type_systems')->updateOrInsert(
            ['event_type_id' => $eventType->id, 'system_id' => $systemId],
            ['created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['message' => 'Sistema asociado.']);
    }

    public function unlinkSystem(EventType $eventType, int $systemId): JsonResponse
    {
        DB::table('event_type_systems')
            ->where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->delete();
        return response()->json(['message' => 'Asociación eliminada.']);
    }

    // ─── Campos del formulario por (tipo, sistema) ────────────────

    public function fields(EventType $eventType, int $systemId): JsonResponse
    {
        $this->resolveSystem($systemId);
        $fields = EventTypeField::where('event_type_id', $eventType->id)
            ->where('system_id', $systemId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        return response()->json($fields);
    }

    public function storeField(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
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
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $data = $this->validateField($request, false);
        $field->update($data);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleField(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
        abort_unless($field->event_type_id === $eventType->id && $field->system_id === $systemId, 404);
        $field->update(['is_active' => ! $field->is_active]);
        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    /** Marca/desmarca el campo para el Reporte de eventos (KPI + filtro). */
    public function toggleReport(EventType $eventType, int $systemId, EventTypeField $field): JsonResponse
    {
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

    public function reorderFields(Request $request, EventType $eventType, int $systemId): JsonResponse
    {
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
        $rows = EventTypeTransition::where('event_type_id', $eventType->id)
            ->get(['from_status_id', 'to_status_id']);
        return response()->json([
            'has_override' => $rows->isNotEmpty(),
            'transitions'  => $rows,
        ]);
    }

    public function setTransitions(Request $request, EventType $eventType): JsonResponse
    {
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
}
