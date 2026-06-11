<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Usuarios
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.toggle-status',
            'users.send-temp-password',
            'users.assign-permissions',

            // Roles
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'roles.assign-permissions',

            // Permisos
            'permissions.view',

            // Perfil
            'profile.view',
            'profile.edit',

            // Clientes
            'clients.view',
            'clients.create',
            'clients.edit',
            'clients.delete',
            'clients.toggle-status',

            // Sitios
            'sites.view',
            'sites.create',
            'sites.edit',
            'sites.delete',
            'sites.toggle-status',

            // Catálogos
            'catalogs.view',
            'catalogs.create',
            'catalogs.edit',
            'catalogs.delete',

            // Administradores de cliente
            'client-admins.view',
            'client-admins.assign',
            'client-admins.remove',

            // Administradores de sitio
            'site-admins.view',
            'site-admins.assign',
            'site-admins.remove',

            // Configuración de sistemas (tipos de dispositivo + plantillas de campos)
            'system-config.view',
            'system-config.manage',

            // Directorios de dispositivos
            'directories.view',
            'directories.create',
            'directories.edit',
            'directories.toggle-status',

            // Dispositivos
            'devices.view',
            'devices.create',
            'devices.edit',
            'devices.toggle-status',

            // Mantenimientos
            'maintenances.view',
            'maintenances.create',
            'maintenances.edit',
            'maintenances.assign-engineers',
            'maintenances.record-activity',

            // Actividades
            'activities.view-registration-date',

            // Configuración del sistema
            'config.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }
}
