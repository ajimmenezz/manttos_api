<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta y baja del token de push del dispositivo (fase 2 del chat).
 *
 * La app lo registra al iniciar sesión y cada vez que el sistema lo rota, y lo da de
 * baja al cerrar sesión: si no, el teléfono seguiría recibiendo los mensajes del
 * usuario anterior.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'       => ['required', 'string', 'max:500'],
            'platform'    => ['required', 'in:' . implode(',', DeviceToken::PLATFORMS)],
            'provider'    => ['nullable', 'in:fcm,apns'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        DeviceToken::register($request->user(), $data['token'], $data['platform'], $data);

        return response()->json(['message' => 'Dispositivo registrado.']);
    }

    /**
     * Baja al cerrar sesión. Se borra por TOKEN (no por usuario) para no tumbar los
     * demás dispositivos de la misma persona.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
        ]);

        DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Dispositivo dado de baja.']);
    }
}
