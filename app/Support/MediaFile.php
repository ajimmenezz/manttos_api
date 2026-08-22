<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resuelve la URL de un archivo subido a algo que un PDF pueda dibujar.
 *
 * Las imágenes se guardan con URL absoluta del servidor que las recibió, así que al
 * imprimir no sirve la URL: hay que llegar al archivo en disco. Se prueban varias
 * formas porque conviven rutas de distintas épocas (`/storage/...`, sólo el nombre,
 * y la carpeta `maintenance-media`).
 *
 * `path()` es lo que conviene para FPDF —lee el archivo directo—; `dataUri()` existe
 * para quien necesita incrustar los bytes (pesa ~33 % más por el base64).
 */
class MediaFile
{
    /** Ruta ABSOLUTA en disco, o null si no se encuentra. */
    public static function path(string $url): ?string
    {
        if ($url === '' || str_starts_with($url, 'data:')) return null;

        try {
            $disk = Storage::disk('public');

            foreach (self::candidates($url) as $rel) {
                if ($disk->exists($rel)) return $disk->path($rel);
            }
        } catch (Throwable) {
            // una imagen ilegible nunca debe tumbar el documento
        }

        return null;
    }

    /** Los bytes incrustados. Devuelve tal cual lo que ya sea un data-URI. */
    public static function dataUri(string $url): ?string
    {
        if (str_starts_with($url, 'data:')) return $url;

        try {
            $disk = Storage::disk('public');

            foreach (self::candidates($url) as $rel) {
                if ($disk->exists($rel)) {
                    return 'data:' . ($disk->mimeType($rel) ?: 'image/jpeg')
                        . ';base64,' . base64_encode($disk->get($rel));
                }
            }
        } catch (Throwable) {
            // idem
        }

        return null;
    }

    /**
     * Copia reducida para imprimir, cacheada en disco.
     *
     * FPDF incrusta los BYTES ORIGINALES de la imagen aunque se dibuje a 21 mm: una
     * bitácora de un mes con evidencia fotográfica pesaba 14.7 MB. Con la copia chica el
     * PDF baja un orden de magnitud y en papel no se nota — a ese tamaño, 320 px sobran.
     *
     * La caché vive en `storage/framework/cache`, NO en los discos que viajan en el
     * respaldo: son archivos derivados y regenerables, no tienen por qué engordar el ZIP.
     */
    public static function thumbnail(?string $absolutePath, int $max = 320): ?string
    {
        if (! $absolutePath || ! is_file($absolutePath)) return null;
        if (! function_exists('imagecreatetruecolor')) return $absolutePath;   // sin GD, va el original

        try {
            $dir = storage_path('framework/cache/pdf-thumbs');
            if (! is_dir($dir)) @mkdir($dir, 0775, true);

            // La fecha del archivo entra en la llave: si se reemplaza la foto, se rehace.
            $cache = $dir . DIRECTORY_SEPARATOR . sha1($absolutePath . filemtime($absolutePath)) . "-{$max}.jpg";
            if (is_file($cache)) return $cache;

            $info = @getimagesize($absolutePath);
            if (! $info) return $absolutePath;

            [$w, $h] = $info;
            if ($w <= $max && $h <= $max) return $absolutePath;   // ya es chica

            $src = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
                IMAGETYPE_PNG  => @imagecreatefrompng($absolutePath),
                IMAGETYPE_GIF  => @imagecreatefromgif($absolutePath),
                default        => null,
            };
            if (! $src) return $absolutePath;

            $scale = $max / max($w, $h);
            $nw    = max(1, (int) round($w * $scale));
            $nh    = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            // Fondo blanco: un PNG con transparencia saldría negro al pasar a JPEG.
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($dst, $cache, 78);
            imagedestroy($dst);
            imagedestroy($src);

            return is_file($cache) ? $cache : $absolutePath;
        } catch (Throwable) {
            return $absolutePath;   // si algo falla, mejor pesada que sin foto
        }
    }

    public static function isImageUrl(mixed $value): bool
    {
        if (! is_string($value) || $value === '') return false;

        if (str_starts_with($value, 'data:image/')) return true;

        $ext = strtolower(pathinfo((string) parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    /** @return string[] rutas relativas al disco público, de la más fiable a la más laxa */
    private static function candidates(string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);

        $candidates = [];
        if (($pos = strpos($path, '/storage/')) !== false) {
            $candidates[] = ltrim(substr($path, $pos + strlen('/storage/')), '/');
        }
        $candidates[] = "maintenance-media/{$base}";
        $candidates[] = $base;

        return array_unique(array_filter($candidates));
    }
}
