<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Superadmin: bypasea todo con Gate::before
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);

        // Admin de cliente: gestiona su(s) cliente(s) y sus sitios
        $adminCliente = Role::firstOrCreate(['name' => 'admin-cliente', 'guard_name' => 'web']);
        $adminCliente->syncPermissions([
            'profile.view', 'profile.edit',
            'clients.view',
            'sites.view', 'sites.create', 'sites.edit', 'sites.toggle-status',
            'client-admins.view',
            'site-admins.view', 'site-admins.assign', 'site-admins.remove',
            'directories.view',
            'devices.view',
            'maintenances.view', 'maintenances.create',
        ]);

        // Admin de sitio: gestiona su(s) sitio(s) y sus directorios/dispositivos
        $adminSitio = Role::firstOrCreate(['name' => 'admin-sitio', 'guard_name' => 'web']);
        $adminSitio->syncPermissions([
            'profile.view', 'profile.edit',
            'clients.view',
            'sites.view',
            'site-admins.view',
            'directories.view', 'directories.create', 'directories.edit', 'directories.toggle-status',
            'devices.view', 'devices.create', 'devices.edit', 'devices.toggle-status',
            'maintenances.view', 'maintenances.create',
        ]);

        // Ingeniero: ejecuta mantenimientos asignados y registra actividades por dispositivo
        $ingeniero = Role::firstOrCreate(['name' => 'ingeniero', 'guard_name' => 'web']);
        $ingeniero->syncPermissions([
            'profile.view', 'profile.edit',
            'maintenances.view',
            'maintenances.record-activity',
        ]);

        // Admin: todos los permisos excepto config.manage (reservado a superadmin)
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::where('name', '!=', 'config.manage')->get());
    }
}
