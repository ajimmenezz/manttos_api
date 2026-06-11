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

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->orderBy('name')->get()->map(function ($role) {
            $role->is_system = in_array($role->name, self::SYSTEM_ROLES);
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

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::SYSTEM_ROLES)) {
            return response()->json(['message' => 'Los roles del sistema no se pueden eliminar.'], 403);
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
