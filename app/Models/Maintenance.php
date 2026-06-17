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
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
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

    public function engineers()
    {
        return $this->belongsToMany(User::class, 'maintenance_engineers')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
