<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
            'archived_at'   => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Solo dispositivos NO archivados ("vaciar directorio" los oculta del directorio,
     * los selectores y los mantenimientos). Se aplica explícitamente en las consultas de
     * listado; NUNCA como global scope, para no romper la relación device() de un evento.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
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
