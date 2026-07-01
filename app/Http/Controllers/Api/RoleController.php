<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    // Roles que no se pueden modificar ni eliminar
    private const SYSTEM_ROLES = ['superadmin', 'admin', 'admin-cliente', 'admin-sitio'];

    // Roles que NUNCA se eliminan (críticos + funcionales referenciados en código).
    // admin y tecnico SÍ pueden eliminarse (si no tienen usuarios) por decisión de negocio.
    private const PROTECTED_FROM_DELETION = ['superadmin', 'admin-cliente', 'admin-sitio', 'ingeniero'];

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->withCount('users')->orderBy('name')->get()->map(function ($role) {
            $role->is_system   = in_array($role->name, self::SYSTEM_ROLES);
            // Eliminable solo si no está protegido y no tiene usuarios asignados.
            $role->is_deletable = ! in_array($role->name, self::PROTECTED_FROM_DELETION) && $role->users_count === 0;
            return $role;
        });

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => 'required|string|max:100|unique:roles,name',
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
        $role->is_system = in_array($role->name, self::SYSTEM_ROLES);
        return response()->json($role->load('permissions'));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if (in_array($role->name, self::SYSTEM_ROLES)) {
            return response()->json(['message' => 'Los roles del sistema no se pueden modificar.'], 403);
        }

        $request->validate([
            'name'          => "required|string|max:100|unique:roles,name,{$role->id}",
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

    public function destroy(Request $request, Role $role): JsonResponse
    {
        // Solo el superadministrador puede eliminar roles.
        abort_unless($request->user()->hasRole('superadmin'), 403, 'Solo el superadministrador puede eliminar roles.');

        if (in_array($role->name, self::PROTECTED_FROM_DELETION)) {
            return response()->json(['message' => 'Este rol es parte del sistema y no se puede eliminar.'], 403);
        }

        // Solo se elimina si no tiene usuarios asignados.
        if ($role->users()->exists()) {
            return response()->json([
                'message'   => 'El rol tiene usuarios asignados. Reasígnalos a otro rol antes de eliminarlo.',
                'has_users' => true,
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Rol eliminado correctamente.']);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
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
