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
            // Ingenieros que atienden sus clientes y sitios
            'client-engineers.view', 'client-engineers.assign', 'client-engineers.remove',
            'site-engineers.view', 'site-engineers.assign', 'site-engineers.remove',
            'directories.view',
            'devices.view',
            'floor-plans.view', 'floor-plans.manage',
            'maintenances.view', 'maintenances.create',
            // Eventos: crea con sólo descripción (el ingeniero captura el formulario)
            'events.view', 'events.create',
        ]);

        // Admin de sitio: gestiona su(s) sitio(s) y sus directorios/dispositivos
        $adminSitio = Role::firstOrCreate(['name' => 'admin-sitio', 'guard_name' => 'web']);
        $adminSitio->syncPermissions([
            'profile.view', 'profile.edit',
            'clients.view',
            'sites.view',
            'site-admins.view',
            // Ingenieros que atienden sus sitios
            'site-engineers.view', 'site-engineers.assign', 'site-engineers.remove',
            'directories.view', 'directories.create', 'directories.edit', 'directories.toggle-status',
            'devices.view', 'devices.create', 'devices.edit', 'devices.toggle-status',
            'floor-plans.view', 'floor-plans.manage',
            'maintenances.view', 'maintenances.create',
            // Eventos: crea con sólo descripción (el ingeniero captura el formulario)
            'events.view', 'events.create',
        ]);

        // Ingeniero: ejecuta mantenimientos asignados y registra actividades por dispositivo
        $ingeniero = Role::firstOrCreate(['name' => 'ingeniero', 'guard_name' => 'web']);
        $ingeniero->syncPermissions([
            'profile.view', 'profile.edit',
            'maintenances.view',
            'maintenances.record-activity',
            'floor-plans.view',
            // Eventos: crea y captura el formulario completo + cambia estado
            'events.view', 'events.create', 'events.fill-form', 'events.change-status',
        ]);

        // Admin: todos los permisos excepto config.manage (reservado a superadmin)
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::where('name', '!=', 'config.manage')->get());
    }
}
