<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rol con archivado (soft delete). Extiende el modelo de Spatie para que toda la
 * librería (relaciones user->roles, cache de permisos) use este modelo vía
 * config/permission.php → models.role.
 */
class Role extends SpatieRole
{
    use SoftDeletes;

    /**
     * Relación con los usuarios del rol. Idéntica a la de Spatie pero fijando el
     * modelo de usuario (User::class) en lugar de resolverlo con getModelForGuard():
     * durante `withCount('users')` Spatie instancia un Role SIN guard_name y, en el
     * ciclo HTTP de este server, getModelForGuard(guard-por-defecto) devolvía null
     * → "Class name must be a valid object or a string" (500). Con el modelo fijo
     * la relación es estable en cualquier contexto.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }
}
