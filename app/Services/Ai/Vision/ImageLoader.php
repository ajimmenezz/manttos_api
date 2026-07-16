<?php

namespace App\Services\Ai\Vision;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puente de MEDIA entre el disco público (donde MediaController y los webhooks
 * dejan las imágenes de captación, en maintenance-media/) y la forma
 * {mime, data(base64)} que consume VisionClient. Fuente ÚNICA de verdad para que
 * el diagnóstico del evento, el agente de captación y el simulador "vean" las
 * mismas fotos igual. También guarda bytes crudos (media entrante de
 * WhatsApp/Telegram) en el mismo directorio y devuelve su URL pública.
 */
class ImageLoader
{
    public const MEDIA_DIR   = 'maintenance-media';
    public const DEFAULT_MAX = 4;

    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Lee URLs públicas de imágenes y las devuelve como {mime, data(base64)}.
     *
     * @param  array<int,string>  $urls
     * @return array<int,array{mime:string,data:string}>
     */
    public static function fromUrls(array $urls, int $max = self::DEFAULT_MAX): array
    {
        $disk = Storage::disk('public');
        $out = [];
        foreach (array_slice(array_values($urls), 0, $max) as $url) {
            $base = basename((string) parse_url((string) $url, PHP_URL_PATH));
            $rel  = self::MEDIA_DIR . '/' . $base;
            if ($base === '' || ! $disk->exists($rel)) {
                continue;
            }
            $bytes = $disk->get($rel);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $out[] = ['mime' => self::mimeFor($base), 'data' => base64_encode($bytes)];
        }

        return $out;
    }

    /**
     * Guarda bytes crudos de una imagen entrante en el disco público y devuelve su
     * URL (mismo directorio/estilo que MediaController). Null si no hay contenido.
     */
    public static function store(string $bytes, string $ext = 'jpg'): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg');
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            $ext = 'jpg';
        }

        $path = self::MEDIA_DIR . '/' . Str::uuid()->toString() . '.' . $ext;
        Storage::disk('public')->put($path, $bytes);

        return Storage::disk('public')->url($path);
    }

    public static function mimeFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
