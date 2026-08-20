<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Secciones visibles/ocultas de un reporte-tablero para un rol o un usuario.
 * Ver App\Support\ReportSections para el catálogo y la resolución.
 */
class ReportSectionSetting extends Model
{
    protected $fillable = ['scope_type', 'scope_id', 'report', 'overrides'];

    protected $casts = ['overrides' => 'array'];
}
