<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Filtro fijo de un plano por directorio (campos del directorio con valores permitidos).
 * `filters` = { field_key: ["v1","v2"], ... }.
 */
class FloorPlanDirectoryFilter extends Model
{
    protected $fillable = ['floor_plan_id', 'directory_id', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    public function floorPlan()
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function directory()
    {
        return $this->belongsTo(Directory::class);
    }
}
