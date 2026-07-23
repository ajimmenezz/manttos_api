<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Maintenance extends Model
{
    public const STATUSES = ['programado', 'en_curso', 'completado', 'cancelado'];
    public const TYPES    = ['normal', 'contrato'];

    protected $fillable = [
        'site_id',
        'catalog_id',
        'type',
        'start_date',
        'end_date',
        'status',
        'notes',
        'agenda_rules',
        'created_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date'   => 'date',
            'end_date'     => 'date',
            'agenda_rules' => 'array',
            'archived_at'  => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    // Nunca como global scope: la exclusión por defecto la hacen las consultas de listado.
    public function scopeVisible($query)  { return $query->whereNull('maintenances.archived_at'); }
    public function scopeArchived($query) { return $query->whereNotNull('maintenances.archived_at'); }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function system()
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }

    public function engineers()
    {
        return $this->belongsToMany(User::class, 'maintenance_engineers')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
