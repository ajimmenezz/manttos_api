<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ActivityTypeField;

class Catalog extends Model
{
    protected $fillable = ['type', 'label', 'nomenclatura', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const TYPE_INDUSTRY      = 'industry';
    public const TYPE_SITE_TYPE     = 'site_type';
    public const TYPE_SYSTEM        = 'system';
    public const TYPE_DEVICE_TYPE   = 'device_type';
    public const TYPE_ACTIVITY_TYPE = 'activity_type';

    public static function types(): array
    {
        return [
            self::TYPE_INDUSTRY      => 'Industrias',
            self::TYPE_SITE_TYPE     => 'Tipos de sitio',
            self::TYPE_SYSTEM        => 'Sistemas',
            self::TYPE_DEVICE_TYPE   => 'Tipos de dispositivo',
            self::TYPE_ACTIVITY_TYPE => 'Tipos de actividad',
        ];
    }

    /** Tipos de dispositivo asignados a este sistema (pivot) */
    public function deviceTypes()
    {
        return $this->belongsToMany(
            Catalog::class,
            'system_device_types',
            'system_catalog_id',
            'device_type_catalog_id'
        );
    }

    /** Sistemas a los que pertenece este tipo de dispositivo (pivot inverso) */
    public function systems()
    {
        return $this->belongsToMany(
            Catalog::class,
            'system_device_types',
            'device_type_catalog_id',
            'system_catalog_id'
        );
    }

    /** Campos de plantilla para este sistema */
    public function fields()
    {
        return $this->hasMany(SystemField::class, 'catalog_id');
    }

    /** Campos de actividad cuando este catalog es un activity_type */
    public function activityTypeFields()
    {
        return $this->hasMany(ActivityTypeField::class, 'activity_type_id');
    }

    /** Campos de actividad cuando este catalog es un system */
    public function activityFieldsForSystem()
    {
        return $this->hasMany(ActivityTypeField::class, 'system_id');
    }

    /** Sistemas asociados a este tipo de actividad (pivot activity_type_systems) */
    public function linkedSystems()
    {
        return $this->belongsToMany(
            Catalog::class,
            'activity_type_systems',
            'activity_type_id',
            'system_id'
        );
    }

    /** Tipos de actividad asociados a este sistema (pivot inverso activity_type_systems) */
    public function activityTypes()
    {
        return $this->belongsToMany(
            Catalog::class,
            'activity_type_systems',
            'system_id',
            'activity_type_id'
        );
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('label');
    }
}
