<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    // Roles que no se pueden modificar
    private const SYSTEM_ROLES = ['superadmin', 'admin', 'admin-cliente', 'admin-sitio'];

    // Roles que NUNCA se archivan (críticos + funcionales referenciados en código).
    // admin y tecnico SÍ pueden archivarse (si no tienen usuarios) por decisión de negocio.
    private const PROTECTED_FROM_DELETION = ['superadmin', 'admin-cliente', 'admin-sitio', 'ingeniero'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('roles.view'), 403, 'No autorizado para esta acción.');

        $roles = Role::with('permissions')->withCount('users')
            // ?archived=1 → solo los archivados (papelera); por defecto solo activos.
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->orderBy('name')->get()->map(function ($role) {
                $role->is_system = in_array($role->name, self::SYSTEM_ROLES);
                // Archivable solo si no está protegido y no tiene usuarios asignados.
                $role->is_archivable = ! in_array($role->name, self::PROTECTED_FROM_DELETION) && $role->users_count === 0;
                return $role;
            });

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('roles.create'), 403, 'No autorizado para esta acción.');

        $request->validate([
            // Ignora roles archivados al validar unicidad (aunque el índice de BD aún reserva el nombre).
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->whereNull('deleted_at')],
            'permissions'   => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create(['name' => $request->name, 'guard_name' => 'web']);

        if ($request->permissions) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json([
            'message' => 'Rol creado correctamente.',
            'role'    => $role->load('permissions'),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        abort_unless(auth()->user()->can('roles.view'), 403, 'No autorizado para esta acción.');

        $role->is_system = in_array($role->name, self::SYSTEM_ROLES);
        return response()->json($role->load('permissions'));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('roles.edit'), 403, 'No autorizado para esta acción.');

        if (in_array($role->name, self::SYSTEM_ROLES)) {
            return response()->json(['message' => 'Los roles del sistema no se pueden modificar.'], 403);
        }

        $request->validate([
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)->whereNull('deleted_at')],
            'permissions'   => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->update(['name' => $request->name]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions ?? []);
        }

        return response()->json([
            'message' => 'Rol actualizado correctamente.',
            'role'    => $role->load('permissions'),
        ]);
    }

    // Archivar = baja lógica (soft delete). Reversible desde "Ver archivados".
    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('roles.archive'), 403, 'No autorizado para esta acción.');

        if (in_array($role->name, self::PROTECTED_FROM_DELETION)) {
            return response()->json(['message' => 'Este rol es parte del sistema y no se puede archivar.'], 403);
        }

        // Solo se archiva si no tiene usuarios (un rol archivado dejaría de conceder permisos).
        if ($role->users()->exists()) {
            return response()->json([
                'message'   => 'El rol tiene usuarios asignados. Reasígnalos a otro rol antes de archivarlo.',
                'has_users' => true,
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Rol archivado.']);
    }

    public function restore(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('roles.archive'), 403, 'No autorizado para esta acción.');

        $role->restore();

        return response()->json(['message' => 'Rol restaurado.', 'role' => $role->load('permissions')]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('roles.assign-permissions'), 403, 'No autorizado para esta acción.');

        if (in_array($role->name, self::SYSTEM_ROLES)) {
            return response()->json(['message' => 'Los permisos de los roles del sistema se gestionan internamente.'], 403);
        }

        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permisos del rol actualizados.',
            'role'    => $role->load('permissions'),
        ]);
    }
}
