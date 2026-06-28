<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    public const NATURES    = ['incidente', 'solicitud']; // extensible: 'problema', 'cambio'
    public const PRIORITIES  = ['baja', 'media', 'alta', 'critica'];

    protected $fillable = [
        'label', 'nature', 'color', 'default_priority',
        'sort_order', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Campos del formulario (todos los sistemas). */
    public function fields()
    {
        return $this->hasMany(EventTypeField::class, 'event_type_id');
    }

    /** Sistemas asociados a este tipo (pivot event_type_systems). */
    public function linkedSystems()
    {
        return $this->belongsToMany(
            Catalog::class,
            'event_type_systems',
            'event_type_id',
            'system_id'
        );
    }

    /** Override de transiciones de este tipo (si existen, mandan sobre las generales). */
    public function transitions()
    {
        return $this->hasMany(EventTypeTransition::class, 'event_type_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
