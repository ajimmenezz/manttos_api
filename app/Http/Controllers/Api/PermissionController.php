<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(auth()->user()->can('permissions.view'), 403, 'No autorizado para esta acción.');

        $permissions = Permission::orderBy('name')->get()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return response()->json($permissions);
    }

    public function flat(): JsonResponse
    {
        abort_unless(auth()->user()->can('permissions.view'), 403, 'No autorizado para esta acción.');

        return response()->json(Permission::orderBy('name')->get());
    }
}
