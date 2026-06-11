<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceActivity extends Model
{
    protected $fillable = [
        'maintenance_id',
        'device_id',
        'activity_type_id',
        'user_id',
        'field_values',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'field_values' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function maintenance()
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function activityType()
    {
        return $this->belongsTo(Catalog::class, 'activity_type_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
