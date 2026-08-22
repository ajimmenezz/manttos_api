<?php

namespace App\Support;

use Throwable;

/**
 * Reduce el peso de una imagen subida sin que se note en pantalla ni en papel.
 *
 * El sistema recibe fotos directas del celular: 3-4 mil píxeles de ancho y varios MB,
 * cuando lo más grande que se muestra es una tarjeta de unos cientos de píxeles y lo más
 * grande que se imprime son 90 mm. Guardar el original completo hace crecer el disco sin
 * darle nada a nadie.
 *
 * Reglas, y el porqué de cada una:
 *  - **Se conserva el formato.** Nada de convertir a WebP: los PDF los dibuja FPDF, que
 *    sólo entiende PNG/JPEG/GIF. Un WebP «más ligero» dejaría las fotos fuera de los
 *    imprimibles.
 *  - **PNG sigue siendo PNG y con su transparencia.** Ahí viven las FIRMAS; aplanarlas a
 *    JPEG les pondría un fondo negro.
 *  - **Se respeta la orientación EXIF antes de tocar nada.** Si se recodifica sin rotar,
 *    las fotos verticales del celular quedan acostadas.
 *  - **Si el resultado pesa más, se descarta.** Recomprimir un JPEG ya optimizado suele
 *    engordarlo, y el objetivo es justo el contrario.
 *  - **Los GIF no se tocan**: pueden estar animados y GD sólo vería el primer cuadro.
 */
class ImageOptimizer
{
    /** Lado mayor. A 300 ppp equivale a ~17 cm impresos: de sobra para evidencia. */
    public const MAX_EDGE = 2000;

    /** Calidad JPEG. Por debajo de ~78 empiezan a verse artefactos en fotos con texto. */
    public const QUALITY = 82;

    /**
     * Perfiles por tipo de imagen. No todo lo que se sube es una foto:
     *
     *  - `photo`: evidencia de campo. Se ve en tarjetas y se imprime a 21-90 mm; 2000 px
     *    sobran y el detalle fino no importa.
     *  - `plan`: PLANOS exportados de AutoCAD. Son dibujos de línea con texto diminuto
     *    que se leen **con zoom**: bajarlos a 2000 px los inutiliza, y una calidad baja
     *    llena de artefactos las líneas finas. Por eso van holgados.
     *  - `logo`: marca del tenant. Nunca se muestra grande, pero es lo primero que se ve
     *    en cada documento, así que va con calidad alta.
     */
    public const PROFILES = [
        'photo' => [2000, 82],
        'plan'  => [6000, 92],
        'logo'  => [1200, 90],
    ];

    /** @return array{0:int,1:int} [lado máximo, calidad] */
    public static function profile(?string $name): array
    {
        return self::PROFILES[$name] ?? self::PROFILES['photo'];
    }

    /**
     * Optimiza el archivo EN SITIO.
     *
     * @return array{ok:bool, before:int, after:int, width:int, height:int, reason:?string}
     */
    public static function optimize(string $path, int $maxEdge = self::MAX_EDGE, int $quality = self::QUALITY): array
    {
        $before = @filesize($path) ?: 0;
        $fail   = fn (string $why) => ['ok' => false, 'before' => $before, 'after' => $before, 'width' => 0, 'height' => 0, 'reason' => $why];

        if (! is_file($path))                        return $fail('no existe');
        if (! function_exists('imagecreatetruecolor')) return $fail('sin GD');

        $info = @getimagesize($path);
        if (! $info) return $fail('no es una imagen legible');

        [$w, $h, $type] = $info;

        if ($type === IMAGETYPE_GIF) return $fail('GIF: puede estar animado');

        // GD descomprime a 4 bytes por píxel y necesita origen Y destino a la vez. Un
        // plano de 10800x7200 pide ~700 MB: sin este guardia el proceso muere por
        // memoria agotada, y eso NO lo atrapa un try/catch — se cae la petición entera
        // y el usuario pierde la subida. Mejor dejar la imagen como está.
        $needed = (int) ($w * $h * 4 * 2.2);
        if (! self::canAfford($needed)) return $fail('demasiado grande para procesar en memoria');

        // Red de seguridad: un DIBUJO (plano de AutoCAD, diagrama) no puede tratarse como
        // una foto aunque nadie lo haya marcado. Se reconoce por el contenido —casi todo
        // blanco y pocos colores—, no por dónde se guardó: los planos entran por el mismo
        // endpoint que las fotos y no siempre quedan registrados como plano.
        if ($maxEdge < self::PROFILES['plan'][0] && self::looksLikeLineArt($path, $type)) {
            [$maxEdge, $quality] = self::PROFILES['plan'];
        }

        try {
            $src = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG  => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
                default        => null,
            };

            if (! $src) return $fail('formato no soportado');

            // La orientación va PRIMERO: rotar después de escalar daría medidas cruzadas.
            $src = self::applyExifOrientation($src, $path, $type, $w, $h);

            $scale = min(1, $maxEdge / max($w, $h));
            $nw    = max(1, (int) round($w * $scale));
            $nh    = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);

            // La transparencia se preserva sólo en los formatos que la tienen; en JPEG
            // habría que rellenar, y no hace falta porque no la soporta.
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            // Se escribe a un temporal y sólo se sustituye si de verdad conviene.
            $tmp = $path . '.opt';

            $written = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($dst, $tmp, $quality),
                IMAGETYPE_PNG  => imagepng($dst, $tmp, 9),
                IMAGETYPE_WEBP => imagewebp($dst, $tmp, $quality),
                default        => false,
            };

            imagedestroy($dst);
            imagedestroy($src);

            if (! $written || ! is_file($tmp)) { @unlink($tmp); return $fail('no se pudo escribir'); }

            $after = filesize($tmp);

            if ($after >= $before) {
                @unlink($tmp);

                return ['ok' => false, 'before' => $before, 'after' => $before, 'width' => $w, 'height' => $h, 'reason' => 'ya estaba optimizada'];
            }

            @rename($tmp, $path);

            return ['ok' => true, 'before' => $before, 'after' => $after, 'width' => $nw, 'height' => $nh, 'reason' => null];
        } catch (Throwable $e) {
            @unlink($path . '.opt');

            // Nunca se pierde el original: si algo falla, el archivo sigue como llegó.
            return $fail($e->getMessage());
        }
    }

    /**
     * Aplica la rotación que trae el EXIF y devuelve el lienzo ya derecho.
     *
     * Las cámaras no rotan el píxel: guardan la foto acostada y una etiqueta que dice
     * cómo verla. Al recodificar se pierde la etiqueta, así que hay que quemar el giro o
     * las fotos verticales salen tumbadas.
     */
    private static function applyExifOrientation($src, string $path, int $type, int &$w, int &$h)
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) return $src;

        try {
            $exif = @exif_read_data($path);
            $o    = (int) ($exif['Orientation'] ?? 0);

            $rotated = match ($o) {
                3       => imagerotate($src, 180, 0),
                6       => imagerotate($src, -90, 0),
                8       => imagerotate($src, 90, 0),
                default => null,
            };

            if (! $rotated) return $src;

            imagedestroy($src);

            if (in_array($o, [6, 8], true)) [$w, $h] = [$h, $w];   // el giro cruza las medidas

            return $rotated;
        } catch (Throwable) {
            return $src;
        }
    }

    /**
     * ¿Es un dibujo de línea y no una fotografía?
     *
     * Un plano exportado de CAD es casi todo papel blanco con trazos finos; una foto
     * tiene tono continuo. Se muestrea una rejilla —no la imagen entera, sería caro— y
     * se mira qué proporción es casi blanca. Si lo es, la imagen se lee CON ZOOM y
     * encogerla la inutiliza, así que pasa al perfil holgado.
     *
     * Ante la duda se prefiere el falso positivo: tratar una foto como plano sólo la
     * deja más pesada; tratar un plano como foto lo arruina.
     */
    private static function looksLikeLineArt(string $path, int $type): bool
    {
        try {
            $img = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG  => @imagecreatefrompng($path),
                default        => null,
            };

            if (! $img) return false;

            $w = imagesx($img); $h = imagesy($img);
            $steps = 60;
            $blancos = 0; $total = 0;

            for ($i = 0; $i < $steps; $i++) {
                for ($j = 0; $j < $steps; $j++) {
                    $c = imagecolorat($img, (int) ($w * $i / $steps), (int) ($h * $j / $steps));
                    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                    $total++;
                    if ($r > 243 && $g > 243 && $b > 243) $blancos++;
                }
            }

            imagedestroy($img);

            return $total > 0 && ($blancos / $total) > 0.65;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ¿Cabe en memoria? Si el límite actual no alcanza, se intenta subir —hasta un
     * techo razonable— y se restaura al terminar el proceso. No se sube sin límite: un
     * servidor con varias subidas a la vez se quedaría sin RAM.
     */
    private static function canAfford(int $needed): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) return true;   // sin límite

        $free = $limit - memory_get_usage(true);
        if ($needed < $free) return true;

        $target = memory_get_usage(true) + $needed;
        // Techo prudente: pedir más que esto por una sola imagen pone en riesgo al
        // servidor cuando hay varias subidas a la vez. La imagen se queda como está.
        if ($target > 512 * 1024 * 1024) return false;

        @ini_set('memory_limit', ((int) ceil($target / 1048576) + 32) . 'M');

        return self::bytes((string) ini_get('memory_limit')) >= $target;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') return 0;

        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }

        return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
    }
}
