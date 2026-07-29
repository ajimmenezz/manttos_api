<?php

namespace App\Support;

use App\Models\CustomCatalog;

/**
 * Resuelve las opciones efectivas de un campo "Lista personalizada" (custom_list /
 * custom_multiselect). Si el campo referencia un catálogo propio
 * (`config.source === 'catalog'` + `config.custom_catalog_id`), inyecta las opciones
 * vivas del catálogo en `config.options`. Así la web, el móvil (vía sync) y los
 * reportes leen `config.options` como si fueran inline, sin lógica extra.
 *
 * Caché por petición para evitar N+1 al servir muchos campos.
 */
class CustomListResolver
{
    /** @var array<int,array<int,array{label:string,value:string}>|null> */
    private static array $cache = [];

    public static function inject(array $config): array
    {
        if (($config['source'] ?? 'inline') !== 'catalog') {
            return $config;
        }
        $id = (int) ($config['custom_catalog_id'] ?? 0);
        if ($id <= 0) {
            $config['options'] = [];
            return $config;
        }
        $config['options'] = self::optionsFor($id);
        return $config;
    }

    /** Opciones normalizadas de un catálogo (cacheadas). Catálogo inactivo/borrado → []. */
    public static function optionsFor(int $id): array
    {
        if (! array_key_exists($id, self::$cache)) {
            $cat = CustomCatalog::find($id);
            self::$cache[$id] = ($cat && $cat->is_active) ? $cat->normalizedOptions() : [];
        }
        return self::$cache[$id] ?? [];
    }

    /** Limpia la caché (tests / tras editar catálogos en el mismo request). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
