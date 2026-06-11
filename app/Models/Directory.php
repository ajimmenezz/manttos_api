<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Directory extends Model
{
    protected $fillable = [
        'site_id',
        'catalog_id',
        'name',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    /** Nombre para mostrar: usa el nombre personalizado o el label del sistema */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: ($this->system?->label ?? 'Sin nombre');
    }
}
