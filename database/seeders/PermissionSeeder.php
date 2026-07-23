<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Catálogo canónico de permisos (esquema granular por acción).
     *
     * Principio: el ALCANCE (qué datos ve un usuario) se resuelve por rol/asignación
     * en los helpers scopeByUser/authorizeXAccess; la CAPACIDAD (qué acción ejecuta)
     * se resuelve con estos permisos vía $user->can('recurso.accion'), verificado
     * ENCIMA del alcance en cada endpoint que muta datos.
     *
     * Corte limpio: al sembrar se PODAN los permisos que ya no están en esta lista
     * (p. ej. los viejos *.delete y events.manage). Correr db:seed --class=PermissionSeeder
     * seguido de permission:cache-reset tras un deploy.
     */
    public const PERMISSIONS = [
        // ── Usuarios ────────────────────────────────────────────────
        'users.view',
        'users.create',
        'users.edit',
        'users.toggle-status',
        'users.archive',              // archivar / restaurar (grantable; antes superadmin-only)
        'users.send-temp-password',
        'users.assign-permissions',   // otorgar permisos directos a un usuario (sensible)

        // ── Roles y permisos ────────────────────────────────────────
        'roles.view',
        'roles.create',
        'roles.edit',                 // renombrar / describir
        'roles.assign-permissions',   // editor de permisos del rol (/roles/[id])
        'roles.archive',
        'permissions.view',

        // ── Perfil (self-service) ───────────────────────────────────
        'profile.view',
        'profile.edit',

        // ── Clientes ────────────────────────────────────────────────
        'clients.view',
        'clients.create',
        'clients.edit',
        'clients.toggle-status',
        'clients.archive',

        // ── Sitios ──────────────────────────────────────────────────
        'sites.view',
        'sites.create',
        'sites.edit',
        'sites.toggle-status',
        'sites.archive',

        // ── Asignaciones (pivotes) ──────────────────────────────────
        'client-admins.view',
        'client-admins.assign',
        'client-admins.remove',
        'site-admins.view',
        'site-admins.assign',
        'site-admins.remove',
        'client-engineers.view',
        'client-engineers.assign',
        'client-engineers.remove',
        'site-engineers.view',
        'site-engineers.assign',
        'site-engineers.remove',

        // ── Directorios ─────────────────────────────────────────────
        'directories.view',
        'directories.create',
        'directories.edit',
        'directories.toggle-status',

        // ── Dispositivos ────────────────────────────────────────────
        'devices.view',
        'devices.create',
        'devices.edit',
        'devices.toggle-status',
        'devices.import',
        'devices.export',
        'devices.archive',              // vaciar directorio / restaurar (reversible)

        // ── Planos del sitio ────────────────────────────────────────
        'floor-plans.view',
        'floor-plans.manage',         // subir / renombrar / eliminar el plano (imagen)
        'floor-plans.place',          // sembrar/quitar dispositivos + filtro fijo del plano

        // ── Catálogos y configuración de sistemas ───────────────────
        'catalogs.view',
        'catalogs.create',
        'catalogs.edit',
        'catalogs.toggle-status',
        'catalogs.merge-device-types', // fusión destructiva de tipos de dispositivo
        'system-config.view',
        'system-config.manage',        // plantillas de campos, DID, frecuencias, tipos por sistema
        'activity-types.configure',    // constructor de formularios de tipos de actividad

        // ── Eventos — configuración ─────────────────────────────────
        'event-config.manage',         // tipos de evento + estados + flujo (reemplaza events.manage)
        'event-sla.manage',            // matriz SLA, niveles, overrides (separado de la config)

        // ── Eventos — operación ─────────────────────────────────────
        'events.view',
        'events.create',
        'events.fill-form',            // capturar formulario / clasificación / dispositivo
        'events.change-status',
        'events.comment',              // conversación / @menciones
        'events.assign',               // asignar/reasignar un evento a un ingeniero (pool)
        'events.archive',              // archivar/restaurar eventos (fuera de interfaz y reportes; superadmin-only por defecto)

        // ── Captación de eventos (WhatsApp/Telegram) ────────────────
        'channels.manage',             // alta y configuración de líneas de mensajería + agente
        'knowledge.manage',            // base de conocimiento de soporte (RAG por sistema) del agente

        // ── Chat interno (comunicación entre usuarios) ──────────────
        'chat.use',                    // participar: conversar 1-a-1 y en los grupos donde esté
        'chat.group-manage',           // crear grupos y administrarlos (renombrar, agregar/quitar)
        'chat.all-conversations',      // soporte/auditoría: leer cualquier hilo (NO escribir en él)

        // ── Solicitantes (usuarios de portal / autoservicio) ────────
        'solicitantes.view',           // ver el directorio de solicitantes (con alcance)
        'solicitantes.create',         // dar de alta solicitantes (incl. importación masiva)
        'solicitantes.edit',           // editar datos / identidad de mensajería / reasignar
        'solicitantes.delete',         // quitar / archivar un solicitante

        // ── Mantenimientos ──────────────────────────────────────────
        'maintenances.view',
        'maintenances.create',
        'maintenances.edit',
        'maintenances.manage-contract', // frecuencias y archivos de contrato (separado de edit)
        'maintenances.assign-engineers',
        'maintenances.record-activity', // registrar / editar / eliminar actividad
        'maintenances.schedule-devices',// programar dispositivos (separado de record-activity)
        'maintenances.action-plan',     // planeación de capacidad + agenda
        'maintenances.archive',         // archivar/restaurar mantenimientos (fuera de listas; superadmin-only por defecto)

        // ── Actividades ─────────────────────────────────────────────
        'activities.view-registration-date',

        // ── Sistema ─────────────────────────────────────────────────
        'config.manage',                // ajustes, SMTP, tema, calendario laboral (superadmin)

        // ── Documentación ───────────────────────────────────────────
        'manuals.view',                 // guías completas web/móvil
        'webhooks.view',                // ver webhooks salientes de su alcance
        'webhooks.manage',              // crear/editar/eliminar/probar webhooks

        // ── Integraciones externas (Odoo, Jira, …) ──────────────────
        'integrations.view',            // ver integraciones y su bitácora (superadmin)
        'integrations.manage',          // configurar/probar/activar integraciones (superadmin)
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Corte limpio: retirar permisos obsoletos que ya no están en el catálogo
        // (viejos *.delete, events.manage, etc.). Detacha en cascada de roles/usuarios.
        Permission::where('guard_name', 'web')
            ->whereNotIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
