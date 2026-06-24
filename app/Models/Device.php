<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    public const STATUSES = [
        'operativo'        => 'Operativo',
        'en_mantenimiento' => 'En mantenimiento',
        'inoperativo'      => 'Inoperativo',
        'dado_de_baja'     => 'Dado de baja',
    ];

    protected $fillable = [
        'directory_id',
        'name',
        'device_type',
        'brand',
        'model',
        'serial_number',
        'location',
        'status',
        'custom_fields',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    public function directory()
    {
        return $this->belongsTo(Directory::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(DeviceFieldValue::class);
    }

    public function placements()
    {
        return $this->hasMany(DevicePlacement::class);
    }
}
