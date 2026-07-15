<?php

namespace App\Services\Ai\Tools;

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceScheduleController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\EventCommentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventDashboardController;
use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\MaintenanceActionPlanController;
use App\Http\Controllers\Api\MaintenanceActivityController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MaintenanceDashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserController;
use App\Models\Client;
use App\Models\Role;
use App\Models\User as UserModel;
use App\Models\Directory;
use App\Models\Event;
use App\Models\Maintenance;
use App\Models\Notification;
use App\Models\Site;
use App\Models\User;
use App\Services\Ai\Tools\Contracts\Tool;

/**
 * Registro central de herramientas del asistente. Fuente única de verdad:
 * lo consumen el agent loop (chat interno) y el servidor MCP. Exporta los
 * esquemas en el formato que espera cada API (OpenAI-compatible vs Anthropic).
 */
class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    /** Registro con el set de herramientas incorporadas. */
    public static function make(): self
    {
        $registry = new self();
        foreach (self::builtins() as $tool) {
            $registry->register($tool);
        }
        return $registry;
    }

    /**
     * Herramientas incorporadas (tabla declarativa vía ControllerTool). Cubre
     * las áreas cuyos controladores autorizan por-usuario. Las áreas con huecos
     * de scoping (directorios/dispositivos) se agregan al cerrarlos.
     */
    private static function builtins(): array
    {
        return [
            // ── Mantenimientos ────────────────────────────────────────────
            new ControllerTool(
                name: 'listar_mantenimientos',
                description: 'Lista los mantenimientos del usuario (respeta su alcance por rol). Filtra por estado, cliente, sitio o fechas.',
                controller: MaintenanceController::class, method: 'myMaintenances',
                properties: [
                    'status'    => ['type' => 'string',  'description' => 'Estado (p. ej. pendiente, en_proceso, completado).'],
                    'client_id' => ['type' => 'integer', 'description' => 'Filtrar por cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'Filtrar por sitio.'],
                    'date_from' => ['type' => 'string',  'description' => 'Desde (YYYY-MM-DD) sobre start_date.'],
                    'date_to'   => ['type' => 'string',  'description' => 'Hasta (YYYY-MM-DD) sobre start_date.'],
                ],
            ),
            new ControllerTool(
                name: 'detalle_mantenimiento',
                description: 'Detalle de un mantenimiento por id (sistema, sitio, cliente, fechas, estado).',
                controller: MaintenanceController::class, method: 'show',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'dashboard_mantenimiento',
                description: 'Tablero/resumen de avance de un mantenimiento (cobertura de dispositivos, actividades).',
                controller: MaintenanceDashboardController::class, method: 'show',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'bitacora_mantenimiento',
                description: 'Bitácora/log de actividades registradas en un mantenimiento.',
                controller: MaintenanceActivityController::class, method: 'log',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
            ),

            // ── Clientes ──────────────────────────────────────────────────
            new ControllerTool(
                name: 'listar_clientes',
                description: 'Lista los clientes visibles para el usuario. Busca por nombre.',
                controller: ClientController::class, method: 'index',
                properties: ['search' => ['type' => 'string', 'description' => 'Texto a buscar en nombre/nombre corto.']],
            ),
            new ControllerTool(
                name: 'detalle_cliente',
                description: 'Detalle de un cliente por id.',
                controller: ClientController::class, method: 'show',
                properties: ['client_id' => ['type' => 'integer', 'description' => 'ID del cliente.']],
                required: ['client_id'],
                bindings: [['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente']],
                shape: 'full',
            ),

            // ── Sitios ────────────────────────────────────────────────────
            new ControllerTool(
                name: 'listar_sitios',
                description: 'Lista los sitios visibles para el usuario. Busca por nombre o ciudad.',
                controller: SiteController::class, method: 'all',
                properties: ['search' => ['type' => 'string', 'description' => 'Texto a buscar en nombre/ciudad.']],
            ),
            new ControllerTool(
                name: 'detalle_sitio',
                description: 'Detalle de un sitio de un cliente (requiere client_id y site_id).',
                controller: SiteController::class, method: 'show',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente dueño del sitio.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
                shape: 'full',
            ),

            // ── Eventos ───────────────────────────────────────────────────
            new ControllerTool(
                name: 'listar_eventos',
                description: 'Lista los eventos (incidentes/solicitudes) visibles. Filtra por cliente, sitio, estado, prioridad o busca por folio/descripción.',
                controller: EventController::class, method: 'index',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'Filtrar por cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'Filtrar por sitio.'],
                    'status_id' => ['type' => 'integer', 'description' => 'Filtrar por estado.'],
                    'priority'  => ['type' => 'string',  'description' => 'Filtrar por prioridad.'],
                    'search'    => ['type' => 'string',  'description' => 'Buscar por folio o descripción.'],
                ],
            ),
            new ControllerTool(
                name: 'detalle_evento',
                description: 'Detalle de un evento por id, incluyendo allowed_transitions (estados a los que puede pasar). Úsala antes de cambiar el estado.',
                controller: EventController::class, method: 'show',
                properties: ['event_id' => ['type' => 'integer', 'description' => 'ID del evento.']],
                required: ['event_id'],
                bindings: [['param' => 'event', 'arg' => 'event_id', 'model' => Event::class, 'label' => 'el evento']],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'comentar_evento',
                description: 'Agrega un comentario a un evento. Requiere event_id (usa listar_eventos para hallarlo) y el texto.',
                controller: EventCommentController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'event_id' => ['type' => 'integer', 'description' => 'ID del evento.'],
                    'body'     => ['type' => 'string',  'description' => 'Texto del comentario.'],
                ],
                required: ['event_id', 'body'],
                bindings: [['param' => 'event', 'arg' => 'event_id', 'model' => Event::class, 'label' => 'el evento']],
                success: 'Comentario agregado al evento.',
            ),
            new ControllerTool(
                name: 'cambiar_estado_evento',
                description: 'Cambia el estado de un evento. PRIMERO usa detalle_evento para ver allowed_transitions y tomar to_status_id. Algunos estados exigen una nota.',
                controller: EventController::class, method: 'changeStatus', verb: 'POST', mutating: true,
                properties: [
                    'event_id'     => ['type' => 'integer', 'description' => 'ID del evento.'],
                    'to_status_id' => ['type' => 'integer', 'description' => 'ID del estado destino (de allowed_transitions).'],
                    'note'         => ['type' => 'string',  'description' => 'Nota del cambio (obligatoria para algunos estados).'],
                ],
                required: ['event_id', 'to_status_id'],
                bindings: [['param' => 'event', 'arg' => 'event_id', 'model' => Event::class, 'label' => 'el evento']],
                success: 'Estado del evento actualizado.',
            ),
            new ControllerTool(
                name: 'asignar_evento',
                description: 'Asigna (o reasigna) un evento del pool a un ingeniero. Requiere event_id y assigned_to (ID del ingeniero, que debe tener alcance al sitio). Para retirar la asignación y devolver el evento al pool, envía assigned_to nulo.',
                controller: EventController::class, method: 'assign', verb: 'POST', mutating: true,
                properties: [
                    'event_id'    => ['type' => 'integer', 'description' => 'ID del evento a asignar.'],
                    'assigned_to' => ['type' => 'integer', 'description' => 'ID del ingeniero asignado (o nulo para regresar al pool).'],
                ],
                required: ['event_id'],
                bindings: [['param' => 'event', 'arg' => 'event_id', 'model' => Event::class, 'label' => 'el evento']],
                success: 'Evento asignado.',
            ),

            // ── Directorios y dispositivos (ya con scoping cerrado) ───────
            new ControllerTool(
                name: 'listar_directorios',
                description: 'Lista plana de todos los directorios visibles para el usuario (con su cliente y sitio). Cada directorio agrupa dispositivos de un sistema.',
                controller: DirectoryController::class, method: 'all',
                properties: [],
            ),
            new ControllerTool(
                name: 'listar_dispositivos',
                description: 'Lista los dispositivos de un directorio (requiere client_id, site_id y directory_id; obtenlos con listar_directorios). Busca por nombre.',
                controller: DeviceController::class, method: 'index',
                properties: [
                    'client_id'    => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'      => ['type' => 'integer', 'description' => 'ID del sitio.'],
                    'directory_id' => ['type' => 'integer', 'description' => 'ID del directorio.'],
                    'search'       => ['type' => 'string',  'description' => 'Texto a buscar en el nombre del dispositivo.'],
                ],
                required: ['client_id', 'site_id', 'directory_id'],
                bindings: [
                    ['param' => 'client',    'arg' => 'client_id',    'model' => Client::class,    'label' => 'el cliente'],
                    ['param' => 'site',      'arg' => 'site_id',      'model' => Site::class,      'label' => 'el sitio'],
                    ['param' => 'directory', 'arg' => 'directory_id', 'model' => Directory::class, 'label' => 'el directorio'],
                ],
            ),
            new ControllerTool(
                name: 'planos_de_sitio',
                description: 'Lista los planos (floor plans) de un sitio (requiere client_id y site_id).',
                controller: FloorPlanController::class, method: 'index',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
            ),

            // ── Más lectura operativa ─────────────────────────────────────
            new ControllerTool(
                name: 'catalogo_clientes',
                description: 'Lista compacta (id, nombre) de clientes activos visibles — útil para resolver un nombre de cliente a su id.',
                controller: ClientController::class, method: 'all',
                properties: [],
            ),
            new ControllerTool(
                name: 'listar_mantenimientos_de_sitio',
                description: 'Mantenimientos de un sitio específico (requiere client_id y site_id). Filtra por estado o fechas.',
                controller: MaintenanceController::class, method: 'index',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                    'status'    => ['type' => 'string',  'description' => 'Estado a filtrar.'],
                    'date_from' => ['type' => 'string',  'description' => 'Desde (YYYY-MM-DD).'],
                    'date_to'   => ['type' => 'string',  'description' => 'Hasta (YYYY-MM-DD).'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
            ),
            new ControllerTool(
                name: 'sistemas_disponibles_sitio',
                description: 'Sistemas de un sitio con directorio activo y al menos un dispositivo (para crear un mantenimiento).',
                controller: MaintenanceController::class, method: 'availableSystems',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
            ),
            new ControllerTool(
                name: 'directorios_de_sitio',
                description: 'Directorios de un sitio con su conteo de dispositivos (requiere client_id y site_id).',
                controller: DirectoryController::class, method: 'index',
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
            ),
            new ControllerTool(
                name: 'ingenieros_de_mantenimiento',
                description: 'Ingenieros asignados a un mantenimiento (requiere client_id, site_id y maintenance_id).',
                controller: MaintenanceController::class, method: 'engineerIndex',
                properties: [
                    'client_id'      => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'        => ['type' => 'integer', 'description' => 'ID del sitio.'],
                    'maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.'],
                ],
                required: ['client_id', 'site_id', 'maintenance_id'],
                bindings: [
                    ['param' => 'client',      'arg' => 'client_id',      'model' => Client::class,      'label' => 'el cliente'],
                    ['param' => 'site',        'arg' => 'site_id',        'model' => Site::class,        'label' => 'el sitio'],
                    ['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento'],
                ],
            ),
            new ControllerTool(
                name: 'tipos_actividad_de_mantenimiento',
                description: 'Tipos de actividad aplicables en un mantenimiento (según su sistema). Úsalo antes de registrar una actividad para obtener activity_type_id.',
                controller: MaintenanceActivityController::class, method: 'activityTypes',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
            ),
            new ControllerTool(
                name: 'dispositivos_de_mantenimiento',
                description: 'Dispositivos disponibles para registrar actividades en un mantenimiento (por su sistema). Úsalo para obtener device_id.',
                controller: MaintenanceActivityController::class, method: 'devices',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
            ),
            new ControllerTool(
                name: 'programacion_de_mantenimiento',
                description: 'Programación de dispositivos (fechas) de un mantenimiento.',
                controller: DeviceScheduleController::class, method: 'index',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
            ),
            new ControllerTool(
                name: 'plan_de_accion_mantenimiento',
                description: 'Plan de acción de un mantenimiento (carga vs capacidad: días, ingenieros, si alcanza). Solo superadmin/admin.',
                controller: MaintenanceActionPlanController::class, method: 'show',
                properties: ['maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.']],
                required: ['maintenance_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'reporte_de_eventos',
                description: 'Reportería agregada de eventos (resumen, distribuciones, por semana). Filtra por cliente, sitio, sistema, tipo, estado, prioridad y fechas.',
                controller: EventDashboardController::class, method: 'show',
                properties: [
                    'client_id'     => ['type' => 'integer', 'description' => 'Filtrar por cliente.'],
                    'site_id'       => ['type' => 'integer', 'description' => 'Filtrar por sitio.'],
                    'system_id'     => ['type' => 'integer', 'description' => 'Filtrar por sistema.'],
                    'event_type_id' => ['type' => 'integer', 'description' => 'Filtrar por tipo de evento.'],
                    'status_id'     => ['type' => 'integer', 'description' => 'Filtrar por estado.'],
                    'priority'      => ['type' => 'string',  'description' => 'Filtrar por prioridad.'],
                    'date_from'     => ['type' => 'string',  'description' => 'Desde (YYYY-MM-DD).'],
                    'date_to'       => ['type' => 'string',  'description' => 'Hasta (YYYY-MM-DD).'],
                ],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'campos_formulario_evento',
                description: 'Campos del formulario de un evento según su tipo y sistema (para saber qué field_values capturar al crear/editar un evento).',
                controller: EventController::class, method: 'formFields',
                properties: [
                    'event_type_id' => ['type' => 'integer', 'description' => 'ID del tipo de evento.'],
                    'system_id'     => ['type' => 'integer', 'description' => 'ID del sistema.'],
                ],
                required: ['event_type_id', 'system_id'],
            ),
            new ControllerTool(
                name: 'listar_usuarios',
                description: 'Lista los usuarios del sistema con sus roles (solo con permiso users.view). Busca por nombre/email o filtra por rol.',
                controller: UserController::class, method: 'index',
                properties: [
                    'search' => ['type' => 'string', 'description' => 'Buscar por nombre o email.'],
                    'role'   => ['type' => 'string', 'description' => 'Filtrar por nombre de rol.'],
                ],
            ),
            new ControllerTool(
                name: 'listar_catalogo',
                description: 'Lista un catálogo administrable (type: industry, site_type, system, device_type, activity_type, event_status_category). Busca por texto.',
                controller: CatalogController::class, method: 'index',
                properties: [
                    'type'   => ['type' => 'string', 'description' => 'Tipo de catálogo.'],
                    'search' => ['type' => 'string', 'description' => 'Texto a buscar.'],
                ],
                required: ['type'],
            ),
            new ControllerTool(
                name: 'mi_perfil',
                description: 'Datos del usuario en sesión (nombre, email, roles y permisos).',
                controller: ProfileController::class, method: 'show',
                properties: [],
                shape: 'full',
            ),
            new ControllerTool(
                name: 'mis_notificaciones',
                description: 'Notificaciones del usuario en sesión, más recientes primero.',
                controller: NotificationController::class, method: 'index',
                properties: [],
            ),

            // ── Escrituras operativas (crear/registrar) ───────────────────
            new ControllerTool(
                name: 'crear_cliente',
                description: 'Da de alta un cliente. Confirma el nombre con el usuario antes de crear.',
                controller: ClientController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'name'       => ['type' => 'string', 'description' => 'Nombre del cliente.'],
                    'short_name' => ['type' => 'string', 'description' => 'Nombre corto (opcional).'],
                    'rfc'        => ['type' => 'string', 'description' => 'RFC (opcional).'],
                    'notes'      => ['type' => 'string', 'description' => 'Notas (opcional).'],
                ],
                required: ['name'],
                success: 'Cliente creado.',
            ),
            new ControllerTool(
                name: 'crear_sitio',
                description: 'Crea un sitio para un cliente (requiere client_id). type es obligatorio (usa catalogo_activo type=site_type).',
                controller: SiteController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'name'      => ['type' => 'string',  'description' => 'Nombre del sitio.'],
                    'type'      => ['type' => 'string',  'description' => 'Tipo de sitio (etiqueta del catálogo site_type).'],
                    'code'      => ['type' => 'string',  'description' => 'Código (opcional).'],
                    'city'      => ['type' => 'string',  'description' => 'Ciudad (opcional).'],
                    'address'   => ['type' => 'string',  'description' => 'Dirección (opcional).'],
                ],
                required: ['client_id', 'name', 'type'],
                bindings: [['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente']],
                success: 'Sitio creado.',
            ),
            new ControllerTool(
                name: 'crear_mantenimiento',
                description: 'Crea un mantenimiento en un sitio (requiere client_id, site_id). catalog_id = sistema (usa sistemas_disponibles_sitio). start_date y end_date en YYYY-MM-DD.',
                controller: MaintenanceController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'client_id'  => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'    => ['type' => 'integer', 'description' => 'ID del sitio.'],
                    'catalog_id' => ['type' => 'integer', 'description' => 'ID del sistema (de sistemas_disponibles_sitio).'],
                    'type'       => ['type' => 'string',  'description' => 'Tipo: normal o contrato (opcional).'],
                    'start_date' => ['type' => 'string',  'description' => 'Fecha inicio (YYYY-MM-DD).'],
                    'end_date'   => ['type' => 'string',  'description' => 'Fecha fin (YYYY-MM-DD, ≥ inicio).'],
                    'notes'      => ['type' => 'string',  'description' => 'Notas (opcional).'],
                ],
                required: ['client_id', 'site_id', 'catalog_id', 'start_date', 'end_date'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
                success: 'Mantenimiento creado.',
            ),
            new ControllerTool(
                name: 'registrar_actividad',
                description: 'Registra una actividad de mantenimiento sobre un dispositivo. Antes obtén device_id (dispositivos_de_mantenimiento) y activity_type_id (tipos_actividad_de_mantenimiento). field_values es un mapa clave→valor según la plantilla del tipo de actividad.',
                controller: MaintenanceActivityController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'maintenance_id'   => ['type' => 'integer', 'description' => 'ID del mantenimiento.'],
                    'device_id'        => ['type' => 'integer', 'description' => 'ID del dispositivo.'],
                    'activity_type_id' => ['type' => 'integer', 'description' => 'ID del tipo de actividad.'],
                    'field_values'     => ['type' => 'object',  'description' => 'Mapa campo_clave→valor según la plantilla (opcional).', 'additionalProperties' => true],
                    'performed_at'     => ['type' => 'string',  'description' => 'Fecha de ejecución (YYYY-MM-DD, opcional).'],
                ],
                required: ['maintenance_id', 'device_id', 'activity_type_id'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
                success: 'Actividad registrada.',
            ),
            new ControllerTool(
                name: 'crear_evento',
                description: 'Da de alta un evento (incidente/solicitud). Requiere site_id, system_id, event_type_id y description. Usa campos_formulario_evento para saber qué field_values capturar. La prioridad se puede dar directa o vía impact+urgency.',
                controller: EventController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'site_id'       => ['type' => 'integer', 'description' => 'ID del sitio.'],
                    'system_id'     => ['type' => 'integer', 'description' => 'ID del sistema.'],
                    'event_type_id' => ['type' => 'integer', 'description' => 'ID del tipo de evento.'],
                    'description'   => ['type' => 'string',  'description' => 'Descripción del evento.'],
                    'device_id'     => ['type' => 'integer', 'description' => 'Dispositivo relacionado (opcional).'],
                    'priority'      => ['type' => 'string',  'description' => 'Prioridad (opcional).'],
                    'impact'        => ['type' => 'string',  'description' => 'Impacto (opcional).'],
                    'urgency'       => ['type' => 'string',  'description' => 'Urgencia (opcional).'],
                    'field_values'  => ['type' => 'object',  'description' => 'Campos del formulario del evento (opcional).', 'additionalProperties' => true],
                ],
                required: ['site_id', 'system_id', 'event_type_id', 'description'],
                success: 'Evento creado.',
            ),
            new ControllerTool(
                name: 'programar_dispositivos',
                description: 'Programa (agenda) dispositivos de un mantenimiento para una fecha futura. device_ids de dispositivos_de_mantenimiento.',
                controller: DeviceScheduleController::class, method: 'store', verb: 'POST', mutating: true,
                properties: [
                    'maintenance_id' => ['type' => 'integer', 'description' => 'ID del mantenimiento.'],
                    'device_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'IDs de dispositivos a programar.'],
                    'scheduled_date' => ['type' => 'string', 'description' => 'Fecha (YYYY-MM-DD, futura).'],
                ],
                required: ['maintenance_id', 'device_ids', 'scheduled_date'],
                bindings: [['param' => 'maintenance', 'arg' => 'maintenance_id', 'model' => Maintenance::class, 'label' => 'el mantenimiento']],
                success: 'Dispositivos programados.',
            ),
            new ControllerTool(
                name: 'marcar_notificacion_leida',
                description: 'Marca una notificación del usuario como leída.',
                controller: NotificationController::class, method: 'markRead', verb: 'POST', mutating: true,
                properties: ['notification_id' => ['type' => 'integer', 'description' => 'ID de la notificación.']],
                required: ['notification_id'],
                bindings: [['param' => 'notification', 'arg' => 'notification_id', 'model' => Notification::class, 'label' => 'la notificación']],
                success: 'Notificación marcada como leída.',
            ),

            // ── Base de conocimiento (RAG del manual) ─────────────────────
            new SearchManualTool(),

            // ── Reportes visuales descargables ────────────────────────────
            new CreateReportTool(),

            // ── Catálogos ─────────────────────────────────────────────────
            new ControllerTool(
                name: 'catalogo_activo',
                description: 'Opciones activas de un catálogo. type puede ser: industry, site_type, system, device_type, activity_type, event_status_category, entre otros.',
                controller: CatalogController::class, method: 'active',
                properties: ['type' => ['type' => 'string', 'description' => 'Tipo de catálogo.']],
                required: ['type'],
                bindings: [['param' => 'type', 'arg' => 'type', 'model' => null]],
                shape: 'full',
            ),

            // ── Acciones SENSIBLES (requieren confirmación humana) ─────────
            new ControllerTool(
                name: 'crear_usuario',
                description: 'Da de alta un usuario (genera contraseña temporal y la envía por correo). Acción sensible: se pedirá confirmación.',
                controller: UserController::class, method: 'store', verb: 'POST', mutating: true, confirm: true,
                properties: [
                    'name'  => ['type' => 'string', 'description' => 'Nombre completo.'],
                    'email' => ['type' => 'string', 'description' => 'Correo (único).'],
                    'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de rol a asignar (opcional).'],
                ],
                required: ['name', 'email'],
                success: 'Usuario creado.',
            ),
            new ControllerTool(
                name: 'editar_usuario',
                description: 'Actualiza nombre/email/roles de un usuario. Acción sensible: se pedirá confirmación.',
                controller: UserController::class, method: 'update', verb: 'PUT', mutating: true, confirm: true,
                properties: [
                    'user_id' => ['type' => 'integer', 'description' => 'ID del usuario.'],
                    'name'    => ['type' => 'string',  'description' => 'Nombre completo.'],
                    'email'   => ['type' => 'string',  'description' => 'Correo.'],
                    'roles'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de rol (opcional).'],
                ],
                required: ['user_id', 'name', 'email'],
                bindings: [['param' => 'user', 'arg' => 'user_id', 'model' => UserModel::class, 'label' => 'el usuario']],
                success: 'Usuario actualizado.',
            ),
            new ControllerTool(
                name: 'cambiar_estado_usuario',
                description: 'Activa o desactiva un usuario. Acción sensible: se pedirá confirmación.',
                controller: UserController::class, method: 'toggleStatus', verb: 'POST', mutating: true, confirm: true,
                properties: ['user_id' => ['type' => 'integer', 'description' => 'ID del usuario.']],
                required: ['user_id'],
                bindings: [['param' => 'user', 'arg' => 'user_id', 'model' => UserModel::class, 'label' => 'el usuario']],
                success: 'Estado del usuario actualizado.',
            ),
            new ControllerTool(
                name: 'asignar_permisos_usuario',
                description: 'Asigna permisos directos a un usuario (reemplaza los actuales). Acción sensible: se pedirá confirmación.',
                controller: UserController::class, method: 'assignPermissions', verb: 'POST', mutating: true, confirm: true,
                properties: [
                    'user_id'     => ['type' => 'integer', 'description' => 'ID del usuario.'],
                    'permissions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de permisos.'],
                ],
                required: ['user_id', 'permissions'],
                bindings: [['param' => 'user', 'arg' => 'user_id', 'model' => UserModel::class, 'label' => 'el usuario']],
                success: 'Permisos del usuario actualizados.',
            ),
            new ControllerTool(
                name: 'crear_rol',
                description: 'Crea un rol con permisos. Acción sensible: se pedirá confirmación.',
                controller: RoleController::class, method: 'store', verb: 'POST', mutating: true, confirm: true,
                properties: [
                    'name'        => ['type' => 'string', 'description' => 'Nombre del rol.'],
                    'permissions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de permisos (opcional).'],
                ],
                required: ['name'],
                success: 'Rol creado.',
            ),
            new ControllerTool(
                name: 'sincronizar_permisos_rol',
                description: 'Reemplaza los permisos de un rol. Acción sensible: se pedirá confirmación.',
                controller: RoleController::class, method: 'syncPermissions', verb: 'POST', mutating: true, confirm: true,
                properties: [
                    'role_id'     => ['type' => 'integer', 'description' => 'ID del rol.'],
                    'permissions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de permisos.'],
                ],
                required: ['role_id', 'permissions'],
                bindings: [['param' => 'role', 'arg' => 'role_id', 'model' => Role::class, 'label' => 'el rol']],
                success: 'Permisos del rol actualizados.',
            ),
            new ControllerTool(
                name: 'archivar_cliente',
                description: 'Archiva (da de baja) un cliente y en cascada sus sitios/mantenimientos/eventos. Acción sensible: se pedirá confirmación.',
                controller: ClientController::class, method: 'destroy', verb: 'DELETE', mutating: true, confirm: true,
                properties: ['client_id' => ['type' => 'integer', 'description' => 'ID del cliente.']],
                required: ['client_id'],
                bindings: [['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente']],
                success: 'Cliente archivado.',
            ),
            new ControllerTool(
                name: 'archivar_sitio',
                description: 'Archiva (da de baja) un sitio de un cliente. Acción sensible: se pedirá confirmación.',
                controller: SiteController::class, method: 'destroy', verb: 'DELETE', mutating: true, confirm: true,
                properties: [
                    'client_id' => ['type' => 'integer', 'description' => 'ID del cliente.'],
                    'site_id'   => ['type' => 'integer', 'description' => 'ID del sitio.'],
                ],
                required: ['client_id', 'site_id'],
                bindings: [
                    ['param' => 'client', 'arg' => 'client_id', 'model' => Client::class, 'label' => 'el cliente'],
                    ['param' => 'site',   'arg' => 'site_id',   'model' => Site::class,   'label' => 'el sitio'],
                ],
                success: 'Sitio archivado.',
            ),
        ];
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return array<string,Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Despacha una llamada de herramienta como el usuario. Siempre devuelve un
     * arreglo serializable (nunca lanza) para alimentar de vuelta al modelo.
     */
    public function dispatch(string $name, array $args, User $user): array
    {
        $tool = $this->get($name);
        if (! $tool) {
            return ['error' => "Herramienta desconocida: {$name}"];
        }
        return $tool->handle($args, $user);
    }

    /**
     * Esquema en formato OpenAI / Ollama (arreglo `tools` con type=function).
     * @return array<int,array>
     */
    public function toOpenAiSchema(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'type'     => 'function',
            'function' => [
                'name'        => $t->name(),
                'description' => $t->description(),
                'parameters'  => $t->parameters(),
            ],
        ], $this->tools));
    }

    /**
     * Esquema en formato Anthropic (arreglo `tools` con input_schema).
     * @return array<int,array>
     */
    public function toAnthropicSchema(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'name'         => $t->name(),
            'description'  => $t->description(),
            'input_schema' => $t->parameters(),
        ], $this->tools));
    }

    /**
     * Esquema en formato MCP (Model Context Protocol) para `tools/list`.
     * Incluye annotations (readOnlyHint/destructiveHint) según sea lectura o
     * escritura, que los clientes MCP usan para decidir si piden confirmación.
     * @return array<int,array>
     */
    public function toMcpSchema(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'name'        => $t->name(),
            'description' => $t->description(),
            'inputSchema' => $t->parameters(),
            'annotations' => [
                'readOnlyHint'    => ! $t->mutating(),
                'destructiveHint' => $t->mutating(),
            ],
        ], $this->tools));
    }
}
