<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettings;
use App\Support\AiPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del Asistente de IA. Global (no por tenant), gateada por
 * `config.manage` (super-admin). Expone el catálogo de proveedores/modelos con
 * la estimación de costo por acción, la elección actual (API key enmascarada)
 * y permite guardarla.
 */
class AiSettingController extends Controller
{
    /** GET /ai/config — catálogo + estimaciones + configuración actual. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $resolved = AiSettings::resolved();

        return response()->json([
            'catalog' => AiPricing::catalogWithEstimates(),
            'current' => [
                'enabled'      => $resolved['enabled'],
                'provider'     => $resolved['provider'],
                'model'        => $resolved['model'],
                'base_url'     => $resolved['base_url'],
                'price_in'     => $resolved['price_in'],
                'price_out'    => $resolved['price_out'],
                'has_api_key'  => AiSettings::hasApiKey(),
                // Nunca devolvemos la key en claro; solo el placeholder si existe.
                'api_key'      => AiSettings::hasApiKey() ? AiSettings::API_KEY_PLACEHOLDER : '',
            ],
        ]);
    }

    /** PUT /ai/config — guarda la elección de proveedor/modelo/key. */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $data = $request->validate([
            'enabled'   => 'sometimes|boolean',
            'provider'  => 'sometimes|string|max:40',
            'model'     => 'sometimes|nullable|string|max:120',
            'api_key'   => 'sometimes|nullable|string|max:500',
            'base_url'  => 'sometimes|nullable|string|max:255',
            'price_in'  => 'sometimes|nullable|numeric|min:0',
            'price_out' => 'sometimes|nullable|numeric|min:0',
        ]);

        // Validar que el proveedor y modelo existan en el catálogo.
        if (isset($data['provider'])) {
            $providers = (array) config('ai.providers', []);
            abort_unless(isset($providers[$data['provider']]), 422, 'Proveedor de IA desconocido.');

            if (! empty($data['model'])) {
                $models = (array) config("ai.providers.{$data['provider']}.models", []);
                abort_unless(isset($models[$data['model']]), 422, 'Modelo no disponible para el proveedor elegido.');
            }
        }

        AiSettings::save($data);

        return $this->show($request);
    }
}
