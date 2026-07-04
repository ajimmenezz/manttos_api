<?php

use App\Http\Controllers\Api\ActivityTypeController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeveloperTokenController;
use App\Http\Middleware\RequireWriteScope;
use App\Http\Controllers\Api\MaintenanceActionPlanController;
use App\Http\Controllers\Api\MaintenanceActivityController;
use App\Http\Controllers\Api\MaintenanceDashboardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientEngineerController;
use App\Http\Controllers\Api\ClientUserController;
use App\Http\Controllers\Api\ClientSystemFieldController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceScheduleController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\DeviceImportExportController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\EventCommentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventDashboardController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\EventStatusController;
use App\Http\Controllers\Api\EventSlaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\WorkCalendarController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SiteEngineerController;
use App\Http\Controllers\Api\SiteUserController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Configuración pública (sin auth — necesaria para login page y providers)
Route::get('/settings/public', [AppSettingController::class, 'publicIndex']);

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // Configuración del sistema
    Route::get('/settings',            [AppSettingController::class, 'index']);
    Route::get('/settings/tenants',    [AppSettingController::class, 'tenants']);
    Route::put('/settings',            [AppSettingController::class, 'update']);
    Route::post('/settings/test-mail', [AppSettingController::class, 'testMail']);

    // Calendario laboral (plan de acción): días/horas + festivos. Restringido a config.manage.
    Route::get('/work-calendar',                       [WorkCalendarController::class, 'show']);
    Route::put('/work-calendar',                       [WorkCalendarController::class, 'update']);
    Route::get('/work-calendar/holidays/suggest',      [WorkCalendarController::class, 'suggestHolidays']);
    Route::post('/work-calendar/holidays/bulk',        [WorkCalendarController::class, 'bulkStoreHolidays']);
    Route::post('/work-calendar/holidays',             [WorkCalendarController::class, 'storeHoliday']);
    Route::delete('/work-calendar/holidays/{holiday}', [WorkCalendarController::class, 'destroyHoliday']);

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // Llaves de API para desarrolladores (gestión desde la sesión del usuario).
    // El propio controlador veda a superadmin/admin.
    Route::get('/developer/tokens',         [DeveloperTokenController::class, 'index']);
    Route::post('/developer/tokens',        [DeveloperTokenController::class, 'store']);
    Route::delete('/developer/tokens/{id}', [DeveloperTokenController::class, 'destroy']);

    // Permisos
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/flat', [PermissionController::class, 'flat']);

    // Roles
    Route::apiResource('/roles', RoleController::class);
    Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    Route::post('/roles/{role}/restore', [RoleController::class, 'restore'])->withTrashed();

    // Usuarios
    Route::apiResource('/users', UserController::class);
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed();
    Route::post('/users/{user}/send-temp-password', [UserController::class, 'sendTempPassword']);
    Route::post('/users/{user}/permissions', [UserController::class, 'assignPermissions']);

    // Clientes
    Route::get('/clients/all', [ClientController::class, 'all']);
    Route::apiResource('/clients', ClientController::class);
    Route::post('/clients/{client}/toggle-status', [ClientController::class, 'toggleStatus']);
    Route::post('/clients/{client}/restore', [ClientController::class, 'restore'])->withTrashed();

    // Sitios — lista plana (acceso del usuario actual) + anidados en cliente
    Route::get('/sites', [SiteController::class, 'all']);
    // Directorios — lista plana scopeada por acceso del usuario (Operaciones → Directorios)
    Route::get('/directories', [DirectoryController::class, 'all']);
    // Compact antes del apiResource para evitar que {site} capture la literal "compact"
    Route::get('/clients/{client}/sites/compact', [SiteController::class, 'compact']);
    Route::apiResource('/clients/{client}/sites', SiteController::class);
    Route::post('/clients/{client}/sites/{site}/toggle-status', [SiteController::class, 'toggleStatus']);
    Route::post('/clients/{client}/sites/{site}/restore', [SiteController::class, 'restore'])->withTrashed();

    // Administradores de cliente
    Route::get('/clients/{client}/admins', [ClientUserController::class, 'index']);
    Route::get('/clients/{client}/admins/candidates', [ClientUserController::class, 'candidates']);
    Route::post('/clients/{client}/admins', [ClientUserController::class, 'store']);
    Route::delete('/clients/{client}/admins/{user}', [ClientUserController::class, 'destroy']);

    // Ingenieros que atienden al cliente (→ todos sus sitios)
    Route::get('/clients/{client}/engineers', [ClientEngineerController::class, 'index']);
    Route::get('/clients/{client}/engineers/candidates', [ClientEngineerController::class, 'candidates']);
    Route::post('/clients/{client}/engineers', [ClientEngineerController::class, 'store']);
    Route::delete('/clients/{client}/engineers/{user}', [ClientEngineerController::class, 'destroy']);

    // Plantillas personalizadas por cliente (solo superadmin)
    Route::get('/clients/{client}/system-templates', [ClientSystemFieldController::class, 'systemsWithTemplates']);
    Route::prefix('/clients/{client}/systems/{system}')->group(function () {
        Route::get('/fields',                          [ClientSystemFieldController::class, 'index']);
        Route::post('/fields',                         [ClientSystemFieldController::class, 'store']);
        Route::post('/fields/reorder',                 [ClientSystemFieldController::class, 'reorder']);
        Route::put('/fields/{field}',                  [ClientSystemFieldController::class, 'update']);
        Route::post('/fields/{field}/toggle-status',   [ClientSystemFieldController::class, 'toggleStatus']);
        Route::post('/fields/{field}/toggle-dashboard',[ClientSystemFieldController::class, 'toggleDashboard']);
        Route::delete('/fields/{field}',               [ClientSystemFieldController::class, 'destroy']);
    });

    // Catálogos (industrias, tipos de sitio, etc.)
    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::get('/catalogs/active/{type}', [CatalogController::class, 'active']);
    Route::get('/catalogs/{catalog}', [CatalogController::class, 'show']);
    Route::post('/catalogs', [CatalogController::class, 'store']);
    Route::put('/catalogs/{catalog}', [CatalogController::class, 'update']);
    Route::post('/catalogs/{catalog}/toggle-status', [CatalogController::class, 'toggleStatus']);
    Route::delete('/catalogs/{catalog}', [CatalogController::class, 'destroy']);

    // Tipos de dispositivo → sistemas (API inversa)
    Route::get('/device-types/{catalog}/systems',       [SystemController::class, 'deviceTypeSystems']);
    Route::post('/device-types/{catalog}/systems/sync', [SystemController::class, 'syncDeviceTypeSystems']);
    Route::post('/device-types/{catalog}/merge',        [SystemController::class, 'mergeDeviceTypes']);

    // Configuración de sistemas (tipos de dispositivo + plantillas de campos)
    Route::prefix('/systems/{system}')->group(function () {
        Route::get('/device-types',         [SystemController::class, 'deviceTypes']);
        Route::post('/device-types/sync',   [SystemController::class, 'syncDeviceTypes']);
        Route::get('/device-types/active',  [SystemController::class, 'activeDeviceTypes']);
        Route::get('/activity-types',       [SystemController::class, 'activityTypes']);
        Route::get('/frequencies',          [SystemController::class, 'frequencies']);
        Route::post('/frequencies/sync',    [SystemController::class, 'syncFrequencies']);
        Route::get('/task-durations',       [SystemController::class, 'taskDurations']);
        Route::post('/task-durations/sync', [SystemController::class, 'syncTaskDurations']);
        Route::get('/fields',                          [SystemController::class, 'fields']);
        Route::post('/fields',                         [SystemController::class, 'storeField']);
        Route::post('/fields/reorder',                 [SystemController::class, 'reorderFields']);
        Route::get('/fields/{field}/impact',           [SystemController::class, 'fieldImpact']);
        Route::put('/fields/{field}',                  [SystemController::class, 'updateField']);
        Route::post('/fields/{field}/toggle-status',   [SystemController::class, 'toggleField']);
        Route::post('/fields/{field}/toggle-dashboard', [SystemController::class, 'toggleDashboard']);
        Route::post('/fields/{field}/toggle-bitacora',  [SystemController::class, 'toggleBitacora']);
        Route::delete('/fields/{field}',                [SystemController::class, 'destroyField']);
    });

    // Directorios de dispositivos (anidados en sitio)
    Route::apiResource('/clients/{client}/sites/{site}/directories', DirectoryController::class)->except(['destroy']);
    Route::post('/clients/{client}/sites/{site}/directories/{directory}/toggle-status', [DirectoryController::class, 'toggleStatus']);

    // Export / Import de dispositivos (registrar ANTES del apiResource para evitar colisión con {device})
    $devBase = '/clients/{client}/sites/{site}/directories/{directory}/devices';
    Route::get( "{$devBase}/export",         [DeviceImportExportController::class, 'export']);
    Route::post("{$devBase}/import/validate",[DeviceImportExportController::class, 'validateImport']);
    Route::get( "{$devBase}/filter-values",  [DeviceController::class, 'fieldValues']);
    Route::post("{$devBase}/import",          [DeviceImportExportController::class, 'import']);

    // Dispositivos (anidados en directorio)
    Route::apiResource('/clients/{client}/sites/{site}/directories/{directory}/devices', DeviceController::class)->except(['destroy']);
    Route::post('/clients/{client}/sites/{site}/directories/{directory}/devices/{device}/toggle-status', [DeviceController::class, 'toggleStatus']);

    // Media — upload y eliminación de imágenes
    Route::post('/media/upload',  [MediaController::class, 'upload']);
    Route::delete('/media',       [MediaController::class, 'destroy']);

    // Planos del sitio (imagen compartida) + sembrado de dispositivos por sistema
    $planBase = '/clients/{client}/sites/{site}/floor-plans';
    Route::get(   $planBase,                                  [FloorPlanController::class, 'index']);
    Route::post(  $planBase,                                  [FloorPlanController::class, 'store']);
    // estática ANTES de la dinámica {floorPlan} para evitar colisión
    Route::get(   "{$planBase}/placed-devices",              [FloorPlanController::class, 'placedDevices']);
    Route::get(   "{$planBase}/{floorPlan}",                  [FloorPlanController::class, 'show']);
    Route::put(   "{$planBase}/{floorPlan}",                  [FloorPlanController::class, 'update']);
    Route::delete("{$planBase}/{floorPlan}",                  [FloorPlanController::class, 'destroy']);
    Route::post(  "{$planBase}/{floorPlan}/toggle-status",    [FloorPlanController::class, 'toggleStatus']);
    Route::post(  "{$planBase}/{floorPlan}/placements",       [FloorPlanController::class, 'savePlacements']);
    Route::delete("{$planBase}/{floorPlan}/placements",       [FloorPlanController::class, 'clearPlacements']);
    Route::delete("{$planBase}/{floorPlan}/placements/{device}", [FloorPlanController::class, 'deletePlacement']);

    // Tipos de actividad — campos por sistema + asociación a sistemas
    Route::prefix('/activity-types/{activityType}/systems/{system}')->group(function () {
        Route::get('/fields',                        [ActivityTypeController::class, 'fields']);
        Route::post('/fields',                       [ActivityTypeController::class, 'storeField']);
        Route::post('/fields/reorder',               [ActivityTypeController::class, 'reorderFields']);
        Route::put('/fields/{field}',                [ActivityTypeController::class, 'updateField']);
        Route::post('/fields/{field}/toggle-status',  [ActivityTypeController::class, 'toggleField']);
        Route::post('/fields/{field}/toggle-bitacora',[ActivityTypeController::class, 'toggleBitacora']);
        Route::delete('/fields/{field}',              [ActivityTypeController::class, 'destroyField']);
        Route::post('/link',                         [ActivityTypeController::class, 'linkSystem']);
        Route::delete('/link',                       [ActivityTypeController::class, 'unlinkSystem']);
    });
    Route::get('/activity-types/{activityType}/systems', [ActivityTypeController::class, 'systemsWithFields']);

    // ─── Eventos: catálogo de TIPOS de evento + formulario por sistema ───
    Route::get('/event-types',                          [EventTypeController::class, 'index']);
    Route::post('/event-types',                         [EventTypeController::class, 'store']);
    Route::get('/event-types/{eventType}',              [EventTypeController::class, 'show']);
    Route::put('/event-types/{eventType}',              [EventTypeController::class, 'update']);
    Route::post('/event-types/{eventType}/toggle-status', [EventTypeController::class, 'toggleStatus']);
    Route::get('/event-types/{eventType}/systems',      [EventTypeController::class, 'systemsWithFields']);
    Route::get('/event-types/{eventType}/transitions',  [EventTypeController::class, 'transitions']);
    Route::post('/event-types/{eventType}/transitions', [EventTypeController::class, 'setTransitions']);
    Route::prefix('/event-types/{eventType}/systems/{system}')->group(function () {
        Route::get('/fields',                       [EventTypeController::class, 'fields']);
        Route::post('/fields',                      [EventTypeController::class, 'storeField']);
        Route::post('/fields/reorder',              [EventTypeController::class, 'reorderFields']);
        Route::put('/fields/{field}',               [EventTypeController::class, 'updateField']);
        Route::post('/fields/{field}/toggle-status', [EventTypeController::class, 'toggleField']);
        Route::delete('/fields/{field}',            [EventTypeController::class, 'destroyField']);
        Route::post('/link',                        [EventTypeController::class, 'linkSystem']);
        Route::delete('/link',                      [EventTypeController::class, 'unlinkSystem']);
    });

    // ─── Eventos: catálogo de ESTADOS + flujo general ───
    Route::get('/event-statuses',                          [EventStatusController::class, 'index']);
    Route::post('/event-statuses',                         [EventStatusController::class, 'store']);
    Route::put('/event-statuses/{eventStatus}',            [EventStatusController::class, 'update']);
    Route::post('/event-statuses/{eventStatus}/toggle-status', [EventStatusController::class, 'toggleStatus']);
    Route::post('/event-statuses/reorder',                 [EventStatusController::class, 'reorder']);
    Route::post('/event-statuses/transitions',             [EventStatusController::class, 'setTransitions']);

    // ─── Eventos: catálogo de SLA (matriz Impacto×Urgencia, niveles, objetivos, calendario) ───
    Route::get('/event-sla',                        [EventSlaController::class, 'index']);
    Route::get('/event-sla/settings',               [EventSlaController::class, 'settings']);
    Route::put('/event-sla/settings',               [EventSlaController::class, 'saveSettings']);
    Route::delete('/event-sla/settings/{client}',   [EventSlaController::class, 'deleteSettings']);
    Route::post('/event-sla/tiers',                 [EventSlaController::class, 'storeTier']);
    Route::put('/event-sla/tiers/{tier}',           [EventSlaController::class, 'updateTier']);
    Route::delete('/event-sla/tiers/{tier}',        [EventSlaController::class, 'destroyTier']);
    Route::post('/event-sla/tiers/reorder',         [EventSlaController::class, 'reorderTiers']);
    Route::put('/event-sla/status-tiers',           [EventSlaController::class, 'saveStatusTiers']);

    // ─── Eventos: operación (Operaciones → Eventos) ───
    Route::get('/events',                 [EventController::class, 'index']);
    Route::get('/events/form-fields',     [EventController::class, 'formFields']);  // antes del wildcard {event}
    Route::get('/events/dashboard',       [EventDashboardController::class, 'show']); // antes del wildcard {event}
    Route::get('/events/sync-bundle',     [EventController::class, 'syncBundle']);  // antes del wildcard {event}
    Route::get('/events/sla-context',     [EventController::class, 'slaContext']);  // antes del wildcard {event}
    Route::post('/events',                [EventController::class, 'store']);
    Route::get('/events/{event}',         [EventController::class, 'show']);
    Route::put('/events/{event}',         [EventController::class, 'update']);
    Route::post('/events/{event}/status', [EventController::class, 'changeStatus']);

    // Conversación del evento (hilos anidados + @menciones)
    Route::get('/events/{event}/mentionable-users', [EventCommentController::class, 'mentionable']);
    Route::get('/events/{event}/comments',                   [EventCommentController::class, 'index']);
    Route::post('/events/{event}/comments',                  [EventCommentController::class, 'store']);
    Route::put('/events/{event}/comments/{comment}',         [EventCommentController::class, 'update']);
    Route::delete('/events/{event}/comments/{comment}',      [EventCommentController::class, 'destroy']);

    // Notificaciones in-app (centro / campanita)
    Route::get('/notifications',                 [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count',    [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all',       [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // Vista consolidada de mantenimientos (scope por rol)
    Route::get('/my-maintenances',          [MaintenanceController::class, 'myMaintenances']);
    Route::post('/my-maintenances',         [MaintenanceController::class, 'quickCreate']);
    Route::get('/maintenances/{maintenance}', [MaintenanceController::class, 'show']);
    Route::get('/maintenances/{maintenance}/frequencies',      [MaintenanceController::class, 'frequencies']);
    Route::post('/maintenances/{maintenance}/frequencies/sync', [MaintenanceController::class, 'syncFrequencies']);
    Route::get('/maintenances/{maintenance}/contract-files',                 [MaintenanceController::class, 'contractFiles']);
    Route::post('/maintenances/{maintenance}/contract-files',                [MaintenanceController::class, 'uploadContractFiles']);
    Route::delete('/maintenances/{maintenance}/contract-files/{file}',       [MaintenanceController::class, 'deleteContractFile']);

    // Programación de dispositivos por mantenimiento
    Route::get('/maintenances/{maintenance}/device-schedules',               [DeviceScheduleController::class, 'index']);
    Route::post('/maintenances/{maintenance}/device-schedules',              [DeviceScheduleController::class, 'store']);
    Route::put('/maintenances/{maintenance}/device-schedules/{schedule}',    [DeviceScheduleController::class, 'update']);
    Route::delete('/maintenances/{maintenance}/device-schedules/{schedule}', [DeviceScheduleController::class, 'destroy']);

    // Actividades de mantenimiento
    Route::get('/maintenances/{maintenance}/dashboard',          [MaintenanceDashboardController::class, 'show']);
    Route::get('/maintenances/{maintenance}/contract-dashboard', [MaintenanceDashboardController::class, 'contractDashboard']);
    // Plan de acción (restringido a maintenances.action-plan)
    Route::get('/maintenances/{maintenance}/action-plan',        [MaintenanceActionPlanController::class, 'show']);
    Route::get('/maintenances/{maintenance}/action-plan/agenda', [MaintenanceActionPlanController::class, 'agenda']);
    Route::post('/maintenances/{maintenance}/action-plan/agenda',[MaintenanceActionPlanController::class, 'applyAgenda']);
    Route::get('/maintenances/{maintenance}/activity-types',   [MaintenanceActivityController::class, 'activityTypes']);
    Route::get('/maintenances/{maintenance}/activity-devices', [MaintenanceActivityController::class, 'devices']);
    Route::get('/maintenances/{maintenance}/activity-counts',  [MaintenanceActivityController::class, 'activityCounts']);
    Route::get('/maintenances/{maintenance}/floor-plans',      [MaintenanceActivityController::class, 'floorPlans']);
    Route::get('/maintenances/{maintenance}/log',              [MaintenanceActivityController::class, 'log']);
    Route::post('/maintenances/{maintenance}/activities',                [MaintenanceActivityController::class, 'store']);
    Route::put('/maintenances/{maintenance}/activities/{activity}',     [MaintenanceActivityController::class, 'update']);
    Route::delete('/maintenances/{maintenance}/activities/{activity}',  [MaintenanceActivityController::class, 'destroy']);
    Route::get('/maintenances/{maintenance}/devices/{device}/activities', [MaintenanceActivityController::class, 'deviceActivities']);

    // Mantenimientos de sitio
    Route::prefix('/clients/{client}/sites/{site}/maintenances')->group(function () {
        Route::get('/',                    [MaintenanceController::class, 'index']);
        Route::get('/available-systems',   [MaintenanceController::class, 'availableSystems']);
        Route::post('/',                   [MaintenanceController::class, 'store']);
        Route::put('/{maintenance}',       [MaintenanceController::class, 'update']);

        // Ingenieros asignados al mantenimiento
        Route::get('/{maintenance}/engineers',            [MaintenanceController::class, 'engineerIndex']);
        Route::get('/{maintenance}/engineers/candidates', [MaintenanceController::class, 'engineerCandidates']);
        Route::post('/{maintenance}/engineers',           [MaintenanceController::class, 'engineerStore']);
        Route::delete('/{maintenance}/engineers/{user}',  [MaintenanceController::class, 'engineerDestroy']);
    });

    // Administradores de sitio
    Route::get('/clients/{client}/sites/{site}/admins', [SiteUserController::class, 'index']);
    Route::get('/clients/{client}/sites/{site}/admins/candidates', [SiteUserController::class, 'candidates']);
    Route::post('/clients/{client}/sites/{site}/admins', [SiteUserController::class, 'store']);
    Route::delete('/clients/{client}/sites/{site}/admins/{user}', [SiteUserController::class, 'destroy']);

    // Ingenieros que atienden el sitio
    Route::get('/clients/{client}/sites/{site}/engineers', [SiteEngineerController::class, 'index']);
    Route::get('/clients/{client}/sites/{site}/engineers/candidates', [SiteEngineerController::class, 'candidates']);
    Route::post('/clients/{client}/sites/{site}/engineers', [SiteEngineerController::class, 'store']);
    Route::delete('/clients/{client}/sites/{site}/engineers/{user}', [SiteEngineerController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| API pública versionada (v1) — para fronts de terceros
|--------------------------------------------------------------------------
| Fachada estable sobre los controladores existentes: las llaves de API actúan
| como el usuario que las creó, así que heredan su alcance de datos y permisos.
| Los endpoints mutadores exigen una llave con scope de escritura (RequireWriteScope).
| Documentado en la app: Desarrolladores → APIs.
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Identidad y alcance del token actual
    Route::get('/me', [AuthController::class, 'me']);

    // Clientes y sitios (lectura, scopeada por rol del usuario)
    Route::get('/clients',                        [ClientController::class, 'index']);
    Route::get('/clients/{client}',               [ClientController::class, 'show']);
    Route::get('/clients/{client}/sites',         [SiteController::class, 'index']);
    Route::get('/clients/{client}/sites/{site}',  [SiteController::class, 'show']);
    Route::get('/clients/{client}/sites/{site}/maintenances', [MaintenanceController::class, 'index']);

    // Catálogos activos (industrias, tipos de sitio, sistemas, etc.)
    Route::get('/catalogs/active/{type}', [CatalogController::class, 'active']);

    // Mantenimientos (vista consolidada scopeada) + sub-recursos por mantenimiento
    Route::get('/maintenances',                                  [MaintenanceController::class, 'myMaintenances']);
    Route::get('/maintenances/{maintenance}',                    [MaintenanceController::class, 'show']);
    Route::get('/maintenances/{maintenance}/activity-devices',   [MaintenanceActivityController::class, 'devices']);
    Route::get('/maintenances/{maintenance}/activity-types',     [MaintenanceActivityController::class, 'activityTypes']);
    Route::get('/maintenances/{maintenance}/activity-counts',    [MaintenanceActivityController::class, 'activityCounts']);
    Route::get('/maintenances/{maintenance}/log',                [MaintenanceActivityController::class, 'log']);
    Route::get('/maintenances/{maintenance}/dashboard',          [MaintenanceDashboardController::class, 'show']);
    Route::get('/maintenances/{maintenance}/contract-dashboard', [MaintenanceDashboardController::class, 'contractDashboard']);
    Route::get('/maintenances/{maintenance}/devices/{device}/activities', [MaintenanceActivityController::class, 'deviceActivities']);

    // Escritura: requiere una llave con scope de escritura Y el permiso del usuario.
    Route::middleware(RequireWriteScope::class)->group(function () {
        Route::post('/clients/{client}/sites/{site}/maintenances',              [MaintenanceController::class, 'store']);
        Route::put('/clients/{client}/sites/{site}/maintenances/{maintenance}', [MaintenanceController::class, 'update']);
        Route::post('/maintenances/{maintenance}/activities',                   [MaintenanceActivityController::class, 'store']);
        Route::put('/maintenances/{maintenance}/activities/{activity}',         [MaintenanceActivityController::class, 'update']);
    });
});
