<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevicePlacement extends Model
{
    protected $fillable = [
        'floor_plan_id',
        'device_id',
        'x',
        'y',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
        ];
    }

    public function floorPlan()
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
