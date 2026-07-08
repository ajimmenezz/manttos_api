<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTypeField extends Model
{
    // Mismo conjunto de tipos que el formulario de actividades (motor compartido).
    public const FIELD_TYPES = [
        'text', 'textarea', 'number', 'currency', 'scale',
        'date', 'time', 'datetime', 'boolean', 'list', 'multiselect',
        'image', 'signature', 'leyenda',
    ];

    protected $fillable = [
        'event_type_id',
        'system_id',
        'label',
        'field_key',
        'field_type',
        'catalog_type',
        'legend_text',
        'rules',
        'visibility',
        'config',
        'is_required',
        'max_length',
        'sort_order',
        'is_active',
        'show_in_report',
    ];

    // Tipos de campo explotables como KPI/filtro en el Reporte de eventos.
    public const REPORTABLE_TYPES = ['boolean', 'list', 'multiselect', 'scale', 'number', 'currency'];

    protected function casts(): array
    {
        return [
            'is_required'    => 'boolean',
            'is_active'      => 'boolean',
            'show_in_report' => 'boolean',
            'max_length'  => 'integer',
            'sort_order'  => 'integer',
            'rules'       => 'array',
            'visibility'  => 'array',
            'config'      => 'array',
        ];
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'system_id');
    }
}
