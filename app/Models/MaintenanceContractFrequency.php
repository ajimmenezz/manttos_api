<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceContractFrequency extends Model
{
    protected $fillable = [
        'maintenance_id',
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
