<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Da de alta los permisos de Almacenamiento y los otorga a los roles que ya existen.
 *
 * Va como migración y no solo en los seeders a propósito: `RoleSeeder` hace
 * `syncPermissions`, que deshace los ajustes hechos desde la pantalla de Roles. Aquí
 * solo se AGREGA (`givePermissionTo`), y solo a los roles que existan. Los mismos
 * valores están en los seeders para las instalaciones nuevas.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'storage.view', 'storage.upload', 'storage.manage', 'storage.share',
        'storage.create-free-folders', 'storage.archive',
    ];

    private const GRANTS = [
        // admin: todo menos la papelera (los *.archive son de superadmin, como en RoleSeeder).
        'admin' => ['storage.view', 'storage.upload', 'storage.manage', 'storage.share', 'storage.create-free-folders'],
        'admin-cliente' => ['storage.view', 'storage.upload', 'storage.manage', 'storage.share'],
        'admin-sitio' => ['storage.view', 'storage.upload', 'storage.manage', 'storage.share'],
        'ingeniero' => ['storage.view'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (self::GRANTS as $role => $perms) {
            Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($perms);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
