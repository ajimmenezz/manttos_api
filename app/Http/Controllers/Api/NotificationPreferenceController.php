<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferencias de notificación del usuario: qué escenarios quiere recibir (bandeja +
 * push). El catálogo lo define App\Support\NotificationType; aquí solo se lee/guarda el
 * on/off por usuario. Ausencia de fila = default del catálogo.
 */
class NotificationPreferenceController extends Controller
{
    /** Catálogo con el valor efectivo del usuario (para pintar la lista de switches). */
    public function index(Request $request): JsonResponse
    {
        return response()->json(NotificationPreference::forUser($request->user()->id));
    }

    /** Guarda uno o varios switches. Ignora tipos que no estén en el catálogo. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences'           => 'required|array|min:1',
            'preferences.*.type'    => 'required|string',
            'preferences.*.enabled' => 'required|boolean',
        ]);

        $valid = NotificationType::keys();

        foreach ($data['preferences'] as $pref) {
            if (! in_array($pref['type'], $valid, true)) {
                continue;
            }

            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'type' => $pref['type']],
                ['enabled' => $pref['enabled']],
            );
        }

        return response()->json(NotificationPreference::forUser($request->user()->id));
    }
}
