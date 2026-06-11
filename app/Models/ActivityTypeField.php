<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityTypeField extends Model
{
    public const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'list', 'image'];

    protected $fillable = [
        'activity_type_id',
        'system_id',
        'label',
        'field_key',
        'field_type',
        'catalog_type',
        'is_required',
        'max_length',
        'sort_order',
        'is_active',
        'show_in_bitacora',
    ];

    protected function casts(): array
    {
        return [
            'is_required'      => 'boolean',
            'is_active'        => 'boolean',
            'show_in_bitacora' => 'boolean',
            'max_length'       => 'integer',
            'sort_order'       => 'integer',
        ];
    }

    public function activityType()
    {
        return $this->belongsTo(Catalog::class, 'activity_type_id');
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'system_id');
    }
}
