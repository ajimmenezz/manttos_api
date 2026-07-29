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

    /** Opciones normalizadas [{label, value}] (descarta filas vacías). */
    public function normalizedOptions(): array
    {
        $out = [];
        foreach ((array) ($this->options ?? []) as $o) {
            $label = trim((string) ($o['label'] ?? ''));
            $value = trim((string) ($o['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $out[] = ['label' => $label ?: $value, 'value' => $value ?: $label];
        }
        return $out;
    }
}
