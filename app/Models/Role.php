<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol con archivado (soft delete). Extiende el modelo de Spatie para que toda la
 * librería (relaciones user->roles, cache de permisos) use este modelo vía
 * config/permission.php → models.role.
 */
class Role extends SpatieRole
{
    use SoftDeletes;
}
