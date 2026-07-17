<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automatización a nivel actividad: dispara la generación de otra actividad (mismo
 * dispositivo) o de un evento cuando, al documentar una actividad de este tipo, las
 * condiciones (`trigger`, RuleGroup sobre form+directorio) se cumplen. La evaluación
 * y la documentación del destino ocurren en el cliente (web/móvil), reusando el motor
 * de reglas existente; este modelo solo guarda la definición.
 */
class ActivityTypeAutomation extends Model
{
    public const ACTION_TYPES = ['activity', 'event'];

    protected $fillable = [
        'activity_type_id',
        'system_id',
        'name',
        'is_active',
        'sort_order',
        'trigger',
        'action_type',
        'target_activity_type_id',
        'target_event_type_id',
        'prefill',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
            'trigger'    => 'array',
            'prefill'    => 'array',
        ];
    }

    public function targetActivityType()
    {
        return $this->belongsTo(Catalog::class, 'target_activity_type_id');
    }

    public function targetEventType()
    {
        return $this->belongsTo(EventType::class, 'target_event_type_id');
    }
}
