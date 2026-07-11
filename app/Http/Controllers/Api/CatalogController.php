<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    private const VALID_TYPES = ['industry', 'site_type', 'system', 'device_type', 'activity_type', 'event_status_category'];

    /** Lista paginada con búsqueda, filtro de estado y ordenamiento por columna */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.view'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'type'     => 'required|string|in:' . implode(',', self::VALID_TYPES),
            'search'   => 'nullable|string|max:100',
            'status'   => 'nullable|in:all,active,inactive',
            'sort_by'  => 'nullable|in:label,created_at',
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $query = Catalog::where('type', $request->type)
            ->when($request->filled('search'), fn ($q) =>
                $q->where('label', 'ilike', "%{$request->search}%")
            )
            ->when($request->status === 'active',   fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false));

        $sortBy  = in_array($request->sort_by, ['label', 'created_at']) ? $request->sort_by : 'label';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortBy, $sortDir);

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    /** Lista activos de un tipo para selects (sin paginación, ordenada alfabéticamente) */
    public function active(string $type): JsonResponse
    {
        abort_unless(in_array($type, self::VALID_TYPES), 404);

        return response()->json(
            Catalog::ofType($type)->get(['id', 'label'])
        );
    }

    public function show(Catalog $catalog): JsonResponse
    {
        abort_unless(auth()->user()->can('catalogs.view'), 403, 'No autorizado para esta acción.');

        return response()->json($catalog);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.create'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'type'         => 'required|string|in:' . implode(',', self::VALID_TYPES),
            'label'        => 'required|string|max:100',
            'nomenclatura' => 'nullable|string|max:20',
        ]);

        if (Catalog::where('type', $request->type)->where('label', $request->label)->exists()) {
            return response()->json(['message' => 'Ya existe un elemento con ese nombre en este catálogo.'], 422);
        }

        $catalog = Catalog::create([
            'type'         => $request->type,
            'label'        => $request->label,
            'nomenclatura' => $request->type === Catalog::TYPE_DEVICE_TYPE ? $request->nomenclatura : null,
            'is_active'    => true,
        ]);

        return response()->json(['message' => 'Elemento creado.', 'catalog' => $catalog], 201);
    }

    public function update(Request $request, Catalog $catalog): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.edit'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'label'        => 'required|string|max:100',
            'nomenclatura' => 'nullable|string|max:20',
        ]);

        if (
            Catalog::where('type', $catalog->type)
                ->where('label', $request->label)
                ->where('id', '!=', $catalog->id)
                ->exists()
        ) {
            return response()->json(['message' => 'Ya existe un elemento con ese nombre.'], 422);
        }

        $data = ['label' => $request->label];
        if ($catalog->type === Catalog::TYPE_DEVICE_TYPE) {
            $data['nomenclatura'] = $request->nomenclatura;
        }
        $catalog->update($data);

        return response()->json(['message' => 'Elemento actualizado.', 'catalog' => $catalog]);
    }

    public function toggleStatus(Catalog $catalog): JsonResponse
    {
        abort_unless(auth()->user()->can('catalogs.toggle-status'), 403, 'No autorizado para esta acción.');

        // Una categoría de estado de evento no se puede desactivar si tiene estados asociados.
        if ($catalog->is_active
            && $catalog->type === Catalog::TYPE_EVENT_STATUS_CATEGORY
            && \App\Models\EventStatus::where('category_id', $catalog->id)->exists()
        ) {
            return response()->json([
                'message' => 'No se puede desactivar la categoría: tiene estados asociados. Reasigna esos estados primero.',
            ], 422);
        }

        $catalog->update(['is_active' => ! $catalog->is_active]);
        $label = $catalog->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "'{$catalog->label}' {$label}.", 'catalog' => $catalog]);
    }

    /** No eliminamos — solo desactivamos */
    public function destroy(Catalog $catalog): JsonResponse
    {
        abort_unless(auth()->user()->can('catalogs.toggle-status'), 403, 'No autorizado para esta acción.');

        $catalog->update(['is_active' => false]);

        return response()->json(['message' => "'{$catalog->label}' desactivado."]);
    }
}
