<?php

namespace App\Support;

/**
 * Convierte los KPIs dinámicos —campos del formulario y datos del directorio— en bloques
 * que `DashboardPdf` sabe dibujar.
 *
 * Estos KPIs no son fijos: dependen de qué campos marcó cada cliente para reportería, así
 * que ni sus llaves ni su cantidad se pueden escribir en el catálogo. Por eso se ofrecen
 * y se filtran **uno por uno** (`form:<llave>` / `dir:<llave>`), y el mismo `sections` de
 * la descarga sirve para elegirlos sin inventar otro parámetro.
 *
 * Cada tipo se dibuja como lo que es:
 *  - `distribution` y `boolean` → barras (reparto de valores).
 *  - `numeric` → una sola tabla con todos, porque una barra no dice nada de un promedio
 *    y cuatro paneles de «suma / promedio / mín / máx» desperdiciarían media hoja.
 */
class BreakdownBlocks
{
    /** Prefijo de llave por origen; es lo que distingue un campo de formulario de uno de directorio. */
    public const PREFIX = ['form' => 'form:', 'directory' => 'dir:'];

    /** Un reparto con demasiados valores deja de leerse; el resto se resume. */
    private const MAX_ROWS = 12;

    /**
     * @param array    $breakdowns lo que devuelve el reporte en form_breakdowns/directory_breakdowns
     * @param callable $shows      filtro de secciones (`ReportSections::printFilter`)
     * @param string   $source     'form' | 'directory'
     */
    public static function build(array $breakdowns, callable $shows, string $source): array
    {
        $prefix  = self::PREFIX[$source] ?? '';
        $blocks  = [];
        $numeric = [];

        foreach ($breakdowns as $b) {
            $key = $prefix . ($b['key'] ?? '');
            if (! $shows($key)) continue;

            match ($b['kind'] ?? '') {
                'distribution' => $blocks[] = self::distribution($b),
                'boolean'      => $blocks[] = self::boolean($b),
                'numeric'      => $numeric[] = $b,
                default        => null,
            };
        }

        if ($numeric) $blocks[] = self::numericTable($numeric, $source);

        return array_values(array_filter($blocks));
    }

    /** Opciones para el selector de la descarga, con su etiqueta legible. */
    public static function options(array $breakdowns, string $source, string $group): array
    {
        $prefix = self::PREFIX[$source] ?? '';

        return array_values(array_map(fn ($b) => [
            'key'   => $prefix . ($b['key'] ?? ''),
            'group' => $group,
            'label' => (string) ($b['header'] ?? $b['field_label'] ?? $b['key'] ?? ''),
        ], $breakdowns));
    }

    private static function distribution(array $b): ?array
    {
        $rows = $b['distribution'] ?? [];
        if (! $rows) return null;

        // Se ordena por conteo y se recorta: con 40 valores distintos las barras salen
        // ilegibles y el bloque se come una hoja entera.
        usort($rows, fn ($x, $y) => ($y['count'] ?? 0) <=> ($x['count'] ?? 0));

        $shown = array_slice($rows, 0, self::MAX_ROWS);
        $rest  = array_slice($rows, self::MAX_ROWS);

        $out = array_map(fn ($r) => [
            'label' => (string) ($r['value'] ?? '—'),
            'count' => (int) ($r['count'] ?? 0),
        ], $shown);

        if ($rest) {
            $out[] = [
                'label' => 'Otros (' . count($rest) . ')',
                'count' => array_sum(array_map(fn ($r) => (int) ($r['count'] ?? 0), $rest)),
            ];
        }

        return ['type' => 'bars', 'title' => self::title($b), 'rows' => $out];
    }

    private static function boolean(array $b): array
    {
        return [
            'type'  => 'bars',
            'title' => self::title($b),
            'rows'  => [
                ['label' => 'Sí', 'count' => (int) ($b['yes'] ?? 0)],
                ['label' => 'No', 'count' => (int) ($b['no'] ?? 0)],
            ],
        ];
    }

    /** Todos los numéricos en una sola tabla: comparar promedios entre campos es el uso real. */
    private static function numericTable(array $items, string $source): array
    {
        $num = fn ($v) => $v === null ? '—' : (string) $v;

        return [
            'type'  => 'table',
            'title' => $source === 'form' ? 'Campos numéricos del formulario' : 'Datos numéricos del directorio',
            'cols'  => [
                ['label' => 'Campo',      'w' => 78],
                ['label' => 'Capturados', 'w' => 22, 'align' => 'R'],
                ['label' => 'Suma',       'w' => 22, 'align' => 'R'],
                ['label' => 'Promedio',   'w' => 24, 'align' => 'R'],
                ['label' => 'Mínimo',     'w' => 22, 'align' => 'R'],
                ['label' => 'Máximo',     'w' => 22, 'align' => 'R'],
            ],
            'rows' => array_map(fn ($b) => [
                self::title($b),
                ($b['answered'] ?? 0) . ' de ' . ($b['total'] ?? 0),
                $num($b['sum'] ?? null),
                $num($b['avg'] ?? null),
                $num($b['min'] ?? null),
                $num($b['max'] ?? null),
            ], $items),
            'size' => 7,
        ];
    }

    private static function title(array $b): string
    {
        return (string) ($b['header'] ?? $b['field_label'] ?? '—');
    }
}
