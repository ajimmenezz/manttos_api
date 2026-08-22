<?php

namespace App\Support;

use App\Models\AppSetting;
use Throwable;

/**
 * La marca del tenant, resuelta para dibujarla con FPDF.
 *
 * Los imprimibles salían con un azul marino fijo en el código y sin logo, mientras la
 * app y los correos ya respetaban la configuración de cada cliente. Aquí se resuelve
 * una sola vez —logo y colores— y de aquí bebe la base `App\Services\Pdf\Pdf`, así que
 * el membrete de TODOS los formatos sale con la misma identidad que el resto.
 *
 * La paleta sale de `AppSetting::emailColors()`: es el mismo mapa que ya usan los
 * correos, así que un entregable impreso y un correo del sistema se ven de la misma
 * familia sin mantener dos catálogos de color.
 *
 * ⚠️ GOTCHA del logo: `logo_url` vive en las filas del TENANT, no en `default`. La
 * versión anterior leía siempre `default` —donde está vacío— y por eso ningún PDF
 * mostró nunca el logo, aunque estuviera configurado.
 */
class Branding
{
    /** El azul marino histórico: lo que se usaba antes de que hubiera marca. */
    public const FALLBACK_DARK    = [30, 58, 95];
    public const FALLBACK_PRIMARY = [37, 99, 235];

    private function __construct(
        public readonly ?string $logo,
        /** Extremo oscuro de la banda del membrete. */
        public readonly array $dark,
        /** Extremo claro de la banda: con los dos se dibuja el degradado. */
        public readonly array $darkTo,
        /** Color de acento (botones en la app, filete del membrete aquí). */
        public readonly array $primary,
        public readonly string $appName,
    ) {
    }

    public static function for(?string $tenant = null): self
    {
        try {
            $tenant = $tenant ?: AppSetting::DEFAULT_TENANT;
            $map    = AppSetting::allAsMap($tenant);
            $colors = AppSetting::emailColors($tenant);

            $dark    = self::rgb($colors['header_from'] ?? null) ?? self::FALLBACK_DARK;
            $darkTo  = self::rgb($colors['header_to'] ?? null)   ?? $dark;
            $primary = self::rgb($colors['primary'] ?? null)     ?? self::FALLBACK_PRIMARY;

            // El tema por dominio manda sobre el preset cuando trae colores en hex. Los
            // valores en `oklch(...)` —el formato nativo de la app— NO se convierten: se
            // ignoran y queda el preset. Convertir oklch a RGB a mano daría tonos
            // aproximados, y un color de marca equivocado es peor que el del preset.
            $theme = self::decode($map['theme'] ?? null);

            if ($c = self::rgb($theme['colors']['sidebar'] ?? null)) { $dark = $c; $darkTo = $c; }
            if ($c = self::rgb($theme['colors']['primary'] ?? null)) { $primary = $c; }

            return new self(
                logo:    self::resolveLogo($map),
                dark:    $dark,
                darkTo:  $darkTo,
                primary: $primary,
                appName: (string) ($map['app_name'] ?? ''),
            );
        } catch (Throwable) {
            // El membrete es presentación: si la configuración no se puede leer, el
            // reporte sale igual con los colores de siempre.
            return new self(null, self::FALLBACK_DARK, self::FALLBACK_DARK, self::FALLBACK_PRIMARY, '');
        }
    }

    /** Ruta en disco del logo, sólo si es un archivo local que FPDF sepa dibujar. */
    private static function resolveLogo(array $map): ?string
    {
        $url = $map['logo_url'] ?? null;
        if (! $url) return null;

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        if (! str_contains($path, '/storage/')) return null;

        $file = storage_path('app/public/' . ltrim(explode('/storage/', $path, 2)[1], '/'));
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // FPDF sólo dibuja PNG/JPEG/GIF. Un SVG o un WebP se ignoran en silencio: mejor
        // sin logo que con una excepción a medio documento.
        return (is_file($file) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) ? $file : null;
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function rgb(?string $color): ?array
    {
        $color = trim((string) $color);
        if ($color === '') return null;

        if (preg_match('/^#?([0-9a-f]{3})$/i', $color, $m)) {
            [$r, $g, $b] = str_split($m[1]);

            return [hexdec($r . $r), hexdec($g . $g), hexdec($b . $b)];
        }

        if (preg_match('/^#?([0-9a-f]{6})$/i', $color, $m)) {
            return [
                hexdec(substr($m[1], 0, 2)),
                hexdec(substr($m[1], 2, 2)),
                hexdec(substr($m[1], 4, 2)),
            ];
        }

        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i', $color, $m)) {
            return [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
        }

        return null;   // oklch/hsl/nombres CSS: se queda el preset
    }

    private static function decode(mixed $value): array
    {
        if (is_array($value)) return $value;

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
