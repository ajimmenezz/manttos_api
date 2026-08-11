<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Forma canónica del usuario que consume el front (login, /me y suplantación).
 * Un superadmin recibe TODOS los permisos para que la UI pueda renderizar todo;
 * el resto, solo sus permisos efectivos.
 */
class UserPayload
{
    public static function for(User $user): array
    {
        $user->load('roles');

        $permissions = $user->hasRole('superadmin')
            ? Permission::pluck('name')
            : $user->getAllPermissions()->pluck('name')->unique()->values();

        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'must_change_password' => $user->must_change_password,
            'is_active'            => $user->is_active,
            'roles'                => $user->roles->pluck('name'),
            'permissions'          => $permissions,
            'last_login_at'        => $user->last_login_at,
        ];
    }
}
