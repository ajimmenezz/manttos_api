<?php

use App\Http\Controllers\Api\ActivityTypeController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MaintenanceActivityController;
use App\Http\Controllers\Api\MaintenanceDashboardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientUserController;
use App\Http\Controllers\Api\ClientSystemFieldController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceScheduleController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\DeviceImportExportController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SiteController;
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
    Route::put('/settings',            [AppSettingController::class, 'update']);
    Route::post('/settings/test-mail', [AppSettingController::class, 'testMail']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // Permisos
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/flat', [PermissionController::class, 'flat']);

    // Roles
    Route::apiResource('/roles', RoleController::class);
    Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

    // Usuarios
    Route::apiResource('/users', UserController::class);
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::post('/users/{user}/send-temp-password', [UserController::class, 'sendTempPassword']);
    Route::post('/users/{user}/permissions', [UserController::class, 'assignPermissions']);

    // Clientes
    Route::get('/clients/all', [ClientController::class, 'all']);
    Route::apiResource('/clients', ClientController::class);
    Route::post('/clients/{client}/toggle-status', [ClientController::class, 'toggleStatus']);

    // Sitios — lista plana (acceso del usuario actual) + anidados en cliente
    Route::get('/sites', [SiteController::class, 'all']);
    // Compact antes del apiResource para evitar que {site} capture la literal "compact"
    Route::get('/clients/{client}/sites/compact', [SiteController::class, 'compact']);
    Route::apiResource('/clients/{client}/sites', SiteController::class);
    Route::post('/clients/{client}/sites/{site}/toggle-status', [SiteController::class, 'toggleStatus']);

    // Administradores de cliente
    Route::get('/clients/{client}/admins', [ClientUserController::class, 'index']);
    Route::get('/clients/{client}/admins/candidates', [ClientUserController::class, 'candidates']);
    Route::post('/clients/{client}/admins', [ClientUserController::class, 'store']);
    Route::delete('/clients/{client}/admins/{user}', [ClientUserController::class, 'destroy']);

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

    // Vista consolidada de mantenimientos (scope por rol)
    Route::get('/my-maintenances',          [MaintenanceController::class, 'myMaintenances']);
    Route::post('/my-maintenances',         [MaintenanceController::class, 'quickCreate']);
    Route::get('/maintenances/{maintenance}', [MaintenanceController::class, 'show']);

    // Programación de dispositivos por mantenimiento
    Route::get('/maintenances/{maintenance}/device-schedules',               [DeviceScheduleController::class, 'index']);
    Route::post('/maintenances/{maintenance}/device-schedules',              [DeviceScheduleController::class, 'store']);
    Route::put('/maintenances/{maintenance}/device-schedules/{schedule}',    [DeviceScheduleController::class, 'update']);
    Route::delete('/maintenances/{maintenance}/device-schedules/{schedule}', [DeviceScheduleController::class, 'destroy']);

    // Actividades de mantenimiento
    Route::get('/maintenances/{maintenance}/dashboard',        [MaintenanceDashboardController::class, 'show']);
    Route::get('/maintenances/{maintenance}/activity-types',   [MaintenanceActivityController::class, 'activityTypes']);
    Route::get('/maintenances/{maintenance}/activity-devices', [MaintenanceActivityController::class, 'devices']);
    Route::get('/maintenances/{maintenance}/activity-counts',  [MaintenanceActivityController::class, 'activityCounts']);
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
});
