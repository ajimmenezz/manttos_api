<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_SIZE_KB   = 10240; // 10 MB por archivo
    private const DIRECTORY     = 'maintenance-media';

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files'    => 'required|array|min:1|max:20',
            'files.*'  => [
                'required',
                'file',
                'max:' . self::MAX_SIZE_KB,
                'mimes:jpeg,jpg,png,webp,gif',
            ],
        ], [
            'files.required'  => 'Debes enviar al menos un archivo.',
            'files.max'       => 'Máximo 20 imágenes por solicitud.',
            'files.*.max'     => 'Cada imagen no puede superar 10 MB.',
            'files.*.mimes'   => 'Solo se permiten imágenes JPEG, PNG, WebP o GIF.',
        ]);

        // Qué tipo de imagen es, para no tratar un PLANO como una foto de evidencia:
        // el plano se lee con zoom y encogerlo lo inutiliza. Lo manda la pantalla que
        // sube; si no viene, se asume foto, que es el caso mayoritario.
        [$maxEdge, $quality] = ImageOptimizer::profile($request->input('purpose'));

        $urls = [];

        foreach ($request->file('files') as $file) {
            $ext      = strtolower($file->getClientOriginalExtension());
            $name     = Str::uuid()->toString() . '.' . $ext;
            $path     = $file->storeAs(self::DIRECTORY, $name, 'public');

            // Se optimiza en cuanto aterriza: las fotos llegan directas del celular (3-4 mil
            // píxeles, varios MB) y lo más grande que se muestra son unos cientos de píxeles.
            // Guardar el original completo sólo hace crecer el disco. Si algo falla, el
            // archivo se queda tal cual llegó — nunca se pierde la subida por optimizar.
            ImageOptimizer::optimize(Storage::disk('public')->path($path), $maxEdge, $quality);

            $urls[]   = Storage::disk('public')->url($path);
        }

        return response()->json(['urls' => $urls], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'urls'   => 'required|array|min:1',
            'urls.*' => 'required|string',
        ]);

        foreach ($request->urls as $url) {
            // Extraer la ruta relativa desde la URL pública
            $relativePath = self::DIRECTORY . '/' . basename(parse_url($url, PHP_URL_PATH));
            if (Storage::disk('public')->exists($relativePath)) {
                Storage::disk('public')->delete($relativePath);
            }
        }

        return response()->json(['message' => 'Imágenes eliminadas.']);
    }
}
