<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de exportación de un reporte-tablero: qué bloques se imprimen y cómo va la
 * firma. Ver la migración `2026_08_22_000002` para el porqué de guardar los elegidos.
 */
class ReportExportTemplate extends Model
{
    protected $fillable = ['report', 'user_id', 'name', 'sections', 'signature', 'signature_align'];

    protected $casts = ['sections' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
