<?php

namespace App\Models\Concerns;

use App\Support\CustomListResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Para los modelos de campo de formulario (ActivityTypeField/EventTypeField/SystemField):
 * al LEER `config`, si el campo es una "Lista personalizada" que referencia un catálogo
 * propio (`source='catalog'`), inyecta las opciones vivas del catálogo en `config.options`.
 * Al ESCRIBIR, guarda tal cual (JSON). Reemplaza el cast `'config' => 'array'`.
 */
trait ResolvesCustomListConfig
{
    protected function config(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => CustomListResolver::inject(
                is_array($value) ? $value : (json_decode((string) ($value ?? ''), true) ?: [])
            ),
            set: fn ($value) => json_encode($value ?? []),
        );
    }
}
