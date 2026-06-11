<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceSchedule extends Model
{
    protected $fillable = ['maintenance_id', 'device_id', 'scheduled_date', 'created_by'];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date:Y-m-d'];
    }

    public function maintenance() { return $this->belongsTo(Maintenance::class); }
    public function device()      { return $this->belongsTo(Device::class); }
}
