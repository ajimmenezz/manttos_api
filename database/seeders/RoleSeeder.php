<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Roles del sistema con sus permisos por defecto (esquema granular).
     * Los defaults son un punto de partida; se ajustan desde la UI de Roles.
     *
     * - superadmin: bypasea todo con Gate::before (sin permisos sincronizados).
     * - admin: todos los permisos EXCEPTO config.manage y los *.archive
     *   (archivar queda superadmin-only por defecto, pero grantable).
     * - admin-cliente / admin-sitio / ingeniero / tecnico: listas explícitas.
     */
    public function run(): void
    {
        // Superadmin: bypasea todo con Gate::before
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);

        // Admin de cliente: gestiona su(s) cliente(s) y sus sitios/asignaciones.
        // NO crea clientes ni archiva; no gestiona directorios/dispositivos ni planos.
        $adminCliente = Role::firstOrCreate(['name' => 'admin-cliente', 'guard_name' => 'web']);
        $adminCliente->syncPermissions([
            'profile.view', 'profile.edit',
            'clients.view', 'clients.edit', 'clients.toggle-status',
            'sites.view', 'sites.create', 'sites.edit', 'sites.toggle-status',
            'client-admins.view',
            'site-admins.view', 'site-admins.assign', 'site-admins.remove',
            'client-engineers.view', 'client-engineers.assign', 'client-engineers.remove',
            'site-engineers.view', 'site-engineers.assign', 'site-engineers.remove',
            'directories.view',
            'devices.view', 'devices.export',
            'floor-plans.view',
            'maintenances.view', 'maintenances.create',
            'events.view', 'events.create', 'events.comment', 'events.assign',
            'solicitantes.view', 'solicitantes.create', 'solicitantes.edit', 'solicitantes.delete',
            'chat.use', 'chat.group-manage',
        ]);

        // Admin de sitio: gestiona su(s) sitio(s): directorios, dispositivos y planos.
        $adminSitio = Role::firstOrCreate(['name' => 'admin-sitio', 'guard_name' => 'web']);
        $adminSitio->syncPermissions([
            'profile.view', 'profile.edit',
            'clients.view',
            'sites.view', 'sites.edit', 'sites.toggle-status',
            'site-admins.view',
            'site-engineers.view', 'site-engineers.assign', 'site-engineers.remove',
            'directories.view', 'directories.create', 'directories.edit', 'directories.toggle-status',
            'devices.view', 'devices.create', 'devices.edit', 'devices.toggle-status', 'devices.import', 'devices.export',
            'floor-plans.view', 'floor-plans.manage', 'floor-plans.place',
            'maintenances.view', 'maintenances.create',
            'events.view', 'events.create', 'events.comment', 'events.assign',
            'solicitantes.view', 'solicitantes.create', 'solicitantes.edit', 'solicitantes.delete',
            'chat.use', 'chat.group-manage',
        ]);

        // Solicitante: usuario de portal / autoservicio. Solo levanta y da seguimiento
        // a SUS propios eventos (visibilidad restringida a created_by en ScopesEvents) y
        // comenta. Su alcance de creación viene de su asignación a cliente/sitio.
        $solicitante = Role::firstOrCreate(['name' => 'solicitante', 'guard_name' => 'web']);
        $solicitante->syncPermissions([
            'profile.view', 'profile.edit',
            'events.view', 'events.create', 'events.comment',
            'chat.use',
        ]);

        // Ingeniero: ejecuta mantenimientos asignados, registra actividades y programa
        // dispositivos; captura y resuelve eventos.
        $ingeniero = Role::firstOrCreate(['name' => 'ingeniero', 'guard_name' => 'web']);
        $ingeniero->syncPermissions([
            'profile.view', 'profile.edit',
            'floor-plans.view',
            'maintenances.view', 'maintenances.record-activity', 'maintenances.schedule-devices',
            'events.view', 'events.create', 'events.fill-form', 'events.change-status', 'events.comment',
            'chat.use', 'chat.group-manage',
        ]);

        // Técnico: acceso mínimo (perfil propio). Se le otorgan permisos desde la UI.
        $tecnico = Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        $tecnico->syncPermissions([
            'profile.view', 'profile.edit',
            'chat.use',
        ]);

        // Admin: todos los permisos excepto config.manage (superadmin) y los *.archive
        // (superadmin-only por defecto, grantables).
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(
            Permission::where('guard_name', 'web')
                ->where('name', '!=', 'config.manage')
                ->where('name', 'not like', '%.archive')
                ->get()
        );
    }
}
