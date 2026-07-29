<?php

namespace App\Models;

use App\Models\Concerns\ResolvesCustomListConfig;
use Illuminate\Database\Eloquent\Model;

class SystemField extends Model
{
    use ResolvesCustomListConfig;

    public const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'list', 'custom_list', 'image', 'did'];

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
        'show_in_event_report',
        'show_in_service_sheet',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required'       => 'boolean',
            'is_active'         => 'boolean',
            'show_in_dashboard'    => 'boolean',
            'show_in_bitacora'     => 'boolean',
            'show_in_event_report' => 'boolean',
            'show_in_service_sheet' => 'boolean',
            'max_length'           => 'integer',
            'sort_order'        => 'integer',
        ];
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }
}
