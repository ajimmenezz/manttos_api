<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla del reporte ejecutivo de un sitio (ver App\Services\Reports\ExecutiveReport).
 */
class ExecutiveReportTemplate extends Model
{
    protected $fillable = ['site_id', 'system_id', 'name', 'config', 'created_by'];

    protected $casts = ['config' => 'array'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
