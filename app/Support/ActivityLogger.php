<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\ImpersonationLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Escritor central de la Auditoría. Registra CAMBIOS de modelos (con antes/después),
 * acciones HTTP mutadoras sin cambio de modelo (export, etc.) y eventos de sesión.
 * Se registra como singleton por-request para deduplicar (el middleware no vuelve a
 * anotar una acción que ya generó filas de modelo).
 *
 * Regla de oro: un fallo de auditoría NUNCA rompe la operación del usuario.
 */
class ActivityLogger
{
    /** Clase de modelo → módulo (alineado con las llaves de permisos del front). */
    public const MODULES = [
        \App\Models\Catalog::class                    => 'catalogs',
        \App\Models\CustomCatalog::class              => 'catalogs',
        \App\Models\Client::class                     => 'clients',
        \App\Models\Site::class                       => 'sites',
        \App\Models\Directory::class                  => 'directories',
        \App\Models\Device::class                     => 'devices',
        \App\Models\DeviceSchedule::class             => 'devices',
        \App\Models\DeviceChangeRequest::class        => 'devices',
        \App\Models\DevicePlacement::class            => 'floor-plans',
        \App\Models\FloorPlan::class                  => 'floor-plans',
        \App\Models\SystemField::class                => 'system-config',
        \App\Models\ActivityTypeField::class          => 'activity-types',
        \App\Models\ActivityTypeAutomation::class     => 'activity-types',
        \App\Models\EventType::class                  => 'event-config',
        \App\Models\EventTypeField::class             => 'event-config',
        \App\Models\EventTypeTransition::class        => 'event-config',
        \App\Models\EventTypeAutomation::class        => 'event-config',
        \App\Models\EventStatus::class                => 'event-config',
        \App\Models\EventSlaSetting::class            => 'event-sla',
        \App\Models\EventSlaTier::class               => 'event-sla',
        \App\Models\Event::class                      => 'events',
        \App\Models\EventComment::class               => 'events',
        \App\Models\ServiceSheetExport::class         => 'events',
        \App\Models\Maintenance::class                => 'maintenances',
        \App\Models\MaintenanceFrequency::class       => 'maintenances',
        \App\Models\MaintenanceContractFrequency::class => 'maintenances',
        \App\Models\MaintenanceActivity::class        => 'activities',
        \App\Models\User::class                       => 'users',
        \App\Models\Role::class                       => 'roles',
        \App\Models\Holiday::class                    => 'config',
        \App\Models\AppSetting::class                 => 'config',
        \App\Models\NotificationPreference::class     => 'config',
        \App\Models\Integration::class                => 'integrations',
        \App\Models\IntegrationLink::class            => 'integrations',
        \App\Models\Channel::class                    => 'channels',
        \App\Models\CaptureAgentRule::class           => 'channels',
    ];

    /** Nombre legible (singular) por clase, para la descripción. */
    public const NAMES = [
        \App\Models\Catalog::class             => 'elemento de catálogo',
        \App\Models\CustomCatalog::class       => 'lista personalizada',
        \App\Models\Client::class              => 'cliente',
        \App\Models\Site::class                => 'sitio',
        \App\Models\Directory::class           => 'directorio',
        \App\Models\Device::class              => 'dispositivo',
        \App\Models\DeviceSchedule::class      => 'programación de dispositivo',
        \App\Models\DeviceChangeRequest::class => 'solicitud de cambio de dispositivo',
        \App\Models\DevicePlacement::class     => 'ubicación en plano',
        \App\Models\FloorPlan::class           => 'plano',
        \App\Models\SystemField::class         => 'campo de sistema',
        \App\Models\ActivityTypeField::class   => 'campo de tipo de actividad',
        \App\Models\ActivityTypeAutomation::class => 'automatización de actividad',
        \App\Models\EventType::class           => 'tipo de evento',
        \App\Models\EventTypeField::class      => 'campo de tipo de evento',
        \App\Models\EventTypeTransition::class => 'transición de evento',
        \App\Models\EventTypeAutomation::class => 'automatización de evento',
        \App\Models\EventStatus::class         => 'estado de evento',
        \App\Models\EventSlaSetting::class     => 'configuración de SLA',
        \App\Models\EventSlaTier::class        => 'nivel de SLA',
        \App\Models\Event::class               => 'evento',
        \App\Models\EventComment::class        => 'comentario de evento',
        \App\Models\ServiceSheetExport::class  => 'exportación de hojas de servicio',
        \App\Models\Maintenance::class         => 'mantenimiento',
        \App\Models\MaintenanceFrequency::class => 'frecuencia de mantenimiento',
        \App\Models\MaintenanceContractFrequency::class => 'frecuencia de contrato',
        \App\Models\MaintenanceActivity::class => 'actividad',
        \App\Models\User::class                => 'usuario',
        \App\Models\Role::class                => 'rol',
        \App\Models\Holiday::class             => 'día festivo',
        \App\Models\AppSetting::class          => 'ajuste del sistema',
        \App\Models\NotificationPreference::class => 'preferencia de notificaciones',
        \App\Models\Integration::class         => 'integración',
        \App\Models\IntegrationLink::class     => 'integración por cliente',
        \App\Models\Channel::class             => 'línea de captación',
        \App\Models\CaptureAgentRule::class    => 'regla del agente',
    ];

    /** Atributos que nunca se guardan en el detalle. */
    private const IGNORE_ATTRS = [
        'updated_at', 'created_at', 'remember_token', 'password',
        'ai_summary', 'ai_summary_at', 'ai_summary_stale', 'ai_diagnosis', 'ai_diagnosis_at',
    ];

    /** Atributos ignorados por clase (además de los globales). */
    private const MODEL_IGNORE = [
        \App\Models\User::class => ['last_login_at'],
    ];

    /** Llaves cuyo valor se enmascara. */
    private const REDACT = ['password', 'current_password', 'new_password', 'token', 'secret', 'api_key', 'remember_token'];

    private int $modelWrites = 0;
    private ?int $impersonatorId = null;
    private bool $impCalc = false;

    /** ¿Se registraron filas de modelo en este request? (para deduplicar en el middleware). */
    public function modelWrites(): int
    {
        return $this->modelWrites;
    }

    public function isAudited(Model $model): bool
    {
        return isset(self::MODULES[get_class($model)]);
    }

    /** Registra un cambio de modelo (created/updated/deleted) con antes/después. */
    public function logModel(Model $model, string $action): void
    {
        $class  = get_class($model);
        $module = self::MODULES[$class] ?? null;
        if (! $module) return;

        $ignore = array_merge(self::IGNORE_ATTRS, self::MODEL_IGNORE[$class] ?? []);
        $props  = null;

        if ($action === 'updated') {
            $changes = collect($model->getChanges())->except($ignore);
            if ($changes->isEmpty()) return; // solo cambiaron atributos ignorados
            $old = [];
            foreach ($changes->keys() as $k) $old[$k] = $model->getOriginal($k);
            $props = ['old' => $this->redact($old), 'attributes' => $this->redact($changes->all())];
        } elseif ($action === 'created') {
            $props = ['attributes' => $this->redact(collect($model->getAttributes())->except($ignore)->all())];
        } elseif ($action === 'deleted') {
            $props = ['attributes' => $this->redact(collect($model->getOriginal())->except($ignore)->all())];
        }

        $label = $this->subjectLabel($model);
        $noun  = self::NAMES[$class] ?? class_basename($class);
        $verb  = ['created' => 'Creó', 'updated' => 'Editó', 'deleted' => 'Eliminó', 'restored' => 'Restauró'][$action] ?? $action;

        $this->write([
            'source'        => 'model',
            'module'        => $module,
            'action'        => $action,
            'subject_type'  => class_basename($class),
            'subject_id'    => $model->getKey(),
            'subject_label' => $label,
            'description'   => trim("{$verb} {$noun}" . ($label ? " «{$label}»" : '')),
            'properties'    => $props,
        ]);
        $this->modelWrites++;
    }

    /** Registra una acción HTTP mutadora que no generó filas de modelo (export, etc.). */
    public function logRequest(Request $request, int $status): void
    {
        $route  = $request->route();
        $name   = $route ? $route->getName() : null;
        $module = $this->moduleFromPath($request->path());

        $verb = ['POST' => 'Ejecutó', 'PUT' => 'Actualizó', 'PATCH' => 'Actualizó', 'DELETE' => 'Eliminó'][$request->method()] ?? 'Acción';

        $this->write([
            'source'      => 'request',
            'module'      => $module,
            'action'      => $this->actionFromPath($request),
            'description' => "{$verb} · " . ($name ?: $request->method() . ' ' . $request->path()),
            'properties'  => ['params' => $this->redact($this->requestParams($request))],
            'status'      => $status,
        ]);
    }

    /** Registro explícito (login/logout/suplantación/export con más contexto). */
    public function log(string $module, string $action, string $description, array $extra = []): void
    {
        $this->write(array_merge([
            'source'      => $extra['source'] ?? 'request',
            'module'      => $module,
            'action'      => $action,
            'description' => $description,
        ], collect($extra)->except('source')->all()));
    }

    // ── Interno ───────────────────────────────────────────────────────────────

    private function write(array $data): void
    {
        try {
            $user = auth()->user();
            $request = request();

            ActivityLog::create(array_merge([
                'user_id'         => $user?->getKey(),
                'impersonator_id' => $this->impersonatorId(),
                'method'          => $request?->method(),
                'route'           => optional($request?->route())?->getName(),
                'path'            => $request?->path(),
                'ip'              => $request?->ip(),
                'user_agent'      => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'created_at'      => now(),
            ], $data));
        } catch (\Throwable $e) {
            report($e); // auditar nunca debe tumbar la petición
        }
    }

    /** ¿La sesión actual es una suplantación? Devuelve el id del superadmin, si aplica. */
    private function impersonatorId(): ?int
    {
        if ($this->impCalc) return $this->impersonatorId;
        $this->impCalc = true;
        try {
            $token = auth()->user()?->currentAccessToken();
            $tid = ($token && method_exists($token, 'getKey')) ? $token->getKey() : null;
            if ($tid) {
                $this->impersonatorId = ImpersonationLog::whereNull('ended_at')
                    ->where('token_id', $tid)->value('impersonator_id');
            }
        } catch (\Throwable) { /* sin token / no aplica */ }
        return $this->impersonatorId;
    }

    private function subjectLabel(Model $model): ?string
    {
        foreach (['folio', 'label', 'name', 'title', 'email'] as $attr) {
            $v = $model->getAttribute($attr);
            if (is_string($v) && $v !== '') return mb_substr($v, 0, 150);
        }
        return null;
    }

    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), self::REDACT, true)) {
                $data[$k] = '***';
            }
        }
        return $data;
    }

    private function requestParams(Request $request): array
    {
        return collect($request->except(['password', 'current_password', 'new_password', 'token', 'image', 'images']))
            ->take(60)->all();
    }

    private function moduleFromPath(string $path): ?string
    {
        // /api/<segmento>...  → módulo aproximado por el primer segmento significativo.
        $seg = collect(explode('/', $path))->filter()->values();
        $first = $seg->get(0) === 'api' ? $seg->get(1) : $seg->get(0);
        $map = [
            'events' => 'events', 'maintenances' => 'maintenances', 'catalogs' => 'catalogs',
            'custom-catalogs' => 'catalogs', 'clients' => 'clients', 'sites' => 'sites',
            'directories' => 'directories', 'devices' => 'devices', 'users' => 'users',
            'roles' => 'roles', 'event-types' => 'event-config', 'activity-types' => 'activity-types',
            'integrations' => 'integrations', 'impersonate' => 'auth', 'settings' => 'config',
            'my-maintenances' => 'maintenances',
        ];
        return $map[$first] ?? $first;
    }

    private function actionFromPath(Request $request): string
    {
        $path = $request->path();
        if (str_contains($path, 'export')) return 'exported';
        return match ($request->method()) {
            'DELETE' => 'deleted',
            'PUT', 'PATCH' => 'updated',
            default => 'action',
        };
    }
}
