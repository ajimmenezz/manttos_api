<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automatización a nivel EVENTO (server-side). A diferencia de las de actividad, se evalúa
 * y ejecuta en el servidor (EventAutomationEngine + RunEventAutomation), porque las acciones
 * de integración corren en jobs. Guarda: el momento disparador (`event`), las condiciones
 * (`trigger`, RuleGroup) y la acción (`action_kind` + su configuración).
 *
 * @see \App\Services\Events\EventAutomationEngine
 */
class EventTypeAutomation extends Model
{
    // Momentos del ciclo de vida del evento que pueden disparar.
    public const EVENTS = ['created', 'documented', 'status_changed', 'assigned', 'comment_added'];

    // Tipos de acción.
    public const ACTION_KINDS = ['integration', 'query', 'internal', 'event'];

    // Acciones internas soportadas.
    public const INTERNAL_ACTIONS = ['change_status', 'assign', 'comment', 'notify'];

    protected $fillable = [
        'event_type_id',
        'system_id',
        'name',
        'is_active',
        'sort_order',
        'event',
        'status_key',
        'trigger',
        'action_kind',
        'provider',
        'action',
        'params_map',
        'lines_map',
        'result_target',
        'internal_action',
        'internal_config',
        'target_event_type_id',
        'prefill',
        'run_once',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'run_once'        => 'boolean',
            'sort_order'      => 'integer',
            'trigger'         => 'array',
            'params_map'      => 'array',
            'lines_map'       => 'array',
            'internal_config' => 'array',
            'prefill'         => 'array',
        ];
    }

    public function targetEventType()
    {
        return $this->belongsTo(EventType::class, 'target_event_type_id');
    }

    public function runs()
    {
        return $this->hasMany(EventAutomationRun::class, 'event_automation_id');
    }
}
