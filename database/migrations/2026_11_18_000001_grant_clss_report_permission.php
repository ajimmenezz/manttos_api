<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Da de alta `reports.clss` (Reporte CLSS) y lo otorga al rol admin que ya existe. Va como migración por la misma
 * razón que 2026_10_08_000002: `RoleSeeder` hace `syncPermissions` y desharía lo ajustado en la pantalla de Roles.
 * A otros roles (admin-cliente, ingeniero) se les concede desde esa pantalla cuando se decida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'reports.clss', 'guard_name' => 'web']);
        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo('reports.clss');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'reports.clss')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
