<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lista/catálogo reutilizable definido por el usuario. `client_id` null = global.
 * `options` = [{label, value}] (mismo shape que las opciones inline de custom_list).
 */
class CustomCatalog extends Model
{
    protected $fillable = ['client_id', 'name', 'description', 'options', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'options'   => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Opciones normalizadas [{label, value, archived}] (descarta filas vacías).
     * Conserva el flag `archived`: las archivadas NO se ofrecen al capturar (el front las filtra)
     * pero SÍ se incluyen para resolver la etiqueta de valores históricos ya usados.
     */
    public function normalizedOptions(): array
    {
        $out = [];
        foreach ((array) ($this->options ?? []) as $o) {
            $label = trim((string) ($o['label'] ?? ''));
            $value = trim((string) ($o['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $out[] = [
                'label'    => $label ?: $value,
                'value'    => $value ?: $label,
                'archived' => (bool) ($o['archived'] ?? false),
            ];
        }
        return $out;
    }

    /** Solo las opciones activas (para conteos y selección). */
    public function activeOptions(): array
    {
        return array_values(array_filter($this->normalizedOptions(), fn ($o) => ! $o['archived']));
    }
}
