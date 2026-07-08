<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferencias de UI por usuario (clave→JSON). Cualquier usuario autenticado gestiona
 * SOLO las suyas. Ej.: columnas visibles del reporte de eventos.
 */
class UserPreferenceController extends Controller
{
    private function assertKey(string $key): void
    {
        abort_unless(preg_match('/^[a-z][a-z0-9_\-]{1,79}$/', $key) === 1, 422, 'Clave inválida.');
    }

    /** GET /me/preferences/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        $pref = UserPreference::where('user_id', $request->user()->id)->where('key', $key)->first();
        return response()->json(['key' => $key, 'value' => $pref->value ?? null]);
    }

    /** PUT /me/preferences/{key} — upsert del valor (JSON arbitrario). */
    public function update(Request $request, string $key): JsonResponse
    {
        $this->assertKey($key);
        $data = $request->validate(['value' => 'present']);

        $pref = UserPreference::updateOrCreate(
            ['user_id' => $request->user()->id, 'key' => $key],
            ['value' => $data['value']]
        );

        return response()->json(['key' => $key, 'value' => $pref->value]);
    }
}
