<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemField extends Model
{
    public const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'list', 'image', 'did'];

    protected $fillable = [
        'catalog_id',
        'client_id',
        'label',
        'field_key',
        'field_type',
        'catalog_type',
        'config',
        'is_required',
        'max_length',
        'sort_order',
        'is_active',
        'show_in_dashboard',
        'show_in_bitacora',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required'       => 'boolean',
            'is_active'         => 'boolean',
            'show_in_dashboard' => 'boolean',
            'show_in_bitacora'  => 'boolean',
            'max_length'        => 'integer',
            'sort_order'        => 'integer',
            'config'            => 'array',
        ];
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }
}
