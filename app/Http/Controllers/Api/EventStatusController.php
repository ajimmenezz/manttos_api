<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\EventStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $statuses = EventStatus::orderBy('sort_order')->orderBy('id')->get();
        $transitions = DB::table('event_status_transitions')
            ->get(['from_status_id', 'to_status_id']);
        $categories = Catalog::where('type', Catalog::TYPE_EVENT_STATUS_CATEGORY)
            ->orderBy('label')->get(['id', 'label', 'is_active']);

        return response()->json([
            'statuses'    => $statuses,
            'transitions' => $transitions,
            'categories'  => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:80',
            'color'       => 'nullable|string|max:9',
            'category_id'   => 'nullable|integer|exists:catalogs,id',
            'is_initial'    => 'boolean',
            'is_terminal'   => 'boolean',
            'requires_form' => 'boolean',
            'requires_note' => 'boolean',
        ]);
        // La clave es interna y estable: se autogenera del nombre (no la captura el usuario).
        $data['key'] = $this->uniqueKey($data['label']);
        $data['sort_order'] = (EventStatus::max('sort_order') ?? 0) + 1;
        $status = EventStatus::create($data);
        return response()->json(['message' => 'Estado creado.', 'status' => $status], 201);
    }

    public function update(Request $request, EventStatus $eventStatus): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:80',
            'color'       => 'nullable|string|max:9',
            'category_id'   => 'nullable|integer|exists:catalogs,id',
            'is_initial'    => 'boolean',
            'is_terminal'   => 'boolean',
            'requires_form' => 'boolean',
            'requires_note' => 'boolean',
        ]);
        // El 'key' es estable (lo usa la lógica del flujo) → no se edita.
        $eventStatus->update($data);
        return response()->json(['message' => 'Estado actualizado.', 'status' => $eventStatus]);
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'estado';
        $key = $base;
        $i = 2;
        while (EventStatus::where('key', $key)->exists()) {
            $key = $base . '_' . $i;
            $i++;
        }
        return $key;
    }

    public function toggleStatus(EventStatus $eventStatus): JsonResponse
    {
        $eventStatus->update(['is_active' => ! $eventStatus->is_active]);
        return response()->json(['message' => 'Estado actualizado.', 'status' => $eventStatus]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'status_ids'   => 'required|array',
            'status_ids.*' => 'integer|exists:event_statuses,id',
        ]);
        DB::transaction(function () use ($request) {
            foreach ($request->status_ids as $index => $id) {
                EventStatus::where('id', $id)->update(['sort_order' => $index]);
            }
        });
        return response()->json(['message' => 'Orden guardado.']);
    }

    /** Reemplaza el conjunto completo de transiciones GENERALES. */
    public function setTransitions(Request $request): JsonResponse
    {
        $request->validate([
            'transitions'                  => 'present|array',
            'transitions.*.from_status_id' => 'required|integer|exists:event_statuses,id',
            'transitions.*.to_status_id'   => 'required|integer|exists:event_statuses,id',
        ]);

        DB::transaction(function () use ($request) {
            DB::table('event_status_transitions')->delete();
            $now = now();
            foreach ($request->transitions as $t) {
                if ($t['from_status_id'] === $t['to_status_id']) {
                    continue;
                }
                DB::table('event_status_transitions')->updateOrInsert(
                    ['from_status_id' => $t['from_status_id'], 'to_status_id' => $t['to_status_id']],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        });

        return response()->json(['message' => 'Flujo general actualizado.']);
    }
}
