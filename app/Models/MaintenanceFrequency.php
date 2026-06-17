<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceFrequency extends Model
{
    public const UNITS = ['days', 'months', 'years', 'as_needed'];

    protected $fillable = [
        'system_id',
        'device_type_id',
        'activity_type_id',
        'period_value',
        'period_unit',
    ];

    protected function casts(): array
    {
        return [
            'period_value' => 'integer',
        ];
    }
}
