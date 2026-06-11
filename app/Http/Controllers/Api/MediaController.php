<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $urls = [];

        foreach ($request->file('files') as $file) {
            $ext      = strtolower($file->getClientOriginalExtension());
            $name     = Str::uuid()->toString() . '.' . $ext;
            $path     = $file->storeAs(self::DIRECTORY, $name, 'public');
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
