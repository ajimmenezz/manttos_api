<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Un valor capturado, escrito como lo muestra la pantalla.
 *
 * Es el **espejo en PHP de `formatFieldValue` del front** (`app/src/.../bitacora`): lo
 * impreso tiene que decir exactamente lo mismo que se ve en la web, o el entregable y
 * el sistema se contradicen. Si allá cambia el formato de un tipo, aquí también.
 *
 * Lo comparten la bitácora de EVENTOS y la de MANTENIMIENTO, que manejan el mismo
 * vocabulario de tipos (`activity_type_fields` y `event_type_fields` son gemelos).
 */
class FieldValueText
{
    /**
     * Un campo listo para el imprimible: `kind` decide cómo lo dibuja el PDF
     * (texto normal, leyenda con fondo, o rejilla de miniaturas).
     *
     * @return array{label:string,kind:string,value?:string,images?:array}
     */
    public static function field(array $def, mixed $value): array
    {
        $type   = (string) ($def['field_type'] ?? 'text');
        $config = is_array($def['config'] ?? null) ? $def['config'] : [];
        $label  = (string) ($def['label'] ?? '');

        if ($type === 'leyenda') {
            // La leyenda vigente se guarda con la captura; si el registro es viejo y no
            // la trae, se cae al texto configurado hoy.
            $text = is_string($value) && $value !== '' ? $value : (string) ($def['legend_text'] ?? '');

            return ['label' => $label, 'kind' => 'legend', 'value' => $text];
        }

        if ($type === 'image') {
            return ['label' => $label, 'kind' => 'images', 'images' => self::images($value)];
        }

        return ['label' => $label, 'kind' => 'text', 'value' => self::format($value, $type, $config)];
    }

    public static function format(mixed $v, string $type, array $config = []): string
    {
        if ($v === null || $v === '' || $v === []) return '';

        return match ($type) {
            'boolean'   => $v ? 'Sí' : 'No',
            'signature' => $v ? 'Firma capturada' : '',
            'date'      => self::date($v, 'DD [de] MMM [de] YYYY'),
            'datetime'  => self::date($v, 'DD MMM YYYY, HH:mm'),
            'currency'  => '$' . number_format((float) $v, 2) . ' ' . ($config['currency'] ?? 'MXN'),
            'number'    => trim((string) $v . ' ' . ($config['unit'] ?? '')),
            // Listas personalizadas: se guarda el valor y se muestra la etiqueta.
            'custom_list' => self::optionLabel($config, $v),
            'custom_multiselect' => implode(', ', array_map(
                fn ($x) => self::optionLabel($config, $x),
                is_array($v) ? $v : [$v],
            )),
            'multiselect' => is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v,
            default     => is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (string) $v,
        };
    }

    public static function optionLabel(array $config, mixed $value): string
    {
        foreach ($config['options'] ?? [] as $opt) {
            if (($opt['value'] ?? null) == $value) return (string) ($opt['label'] ?? $value);
        }

        return (string) $value;
    }

    /**
     * URLs → {file, url}: `file` es la miniatura que FPDF incrusta y `url` la dirección
     * pública, para dejar la imagen enlazada dentro del PDF.
     */
    public static function images(mixed $value): array
    {
        $urls = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);
        $out  = [];

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') continue;

            $file = MediaFile::thumbnail(MediaFile::path($url));
            if (! $file) continue;

            $out[] = ['file' => $file, 'url' => $url];
        }

        return $out;
    }

    private static function date(mixed $v, string $format): string
    {
        if (! is_string($v)) return (string) $v;

        try {
            return Carbon::parse($v)->locale('es')->isoFormat($format);
        } catch (Throwable) {
            return $v;   // una fecha rara se imprime tal cual, no se pierde
        }
    }
}
