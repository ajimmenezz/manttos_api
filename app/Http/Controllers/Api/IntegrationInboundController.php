<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunIntegration;
use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receptor de llamadas ENTRANTES desde un sistema externo (sync de vuelta). Público, pero
 * autenticado por el secreto de entrada de la integración (header X-Siccob-Integration-Secret
 * o ?secret=). Encola el procesamiento en el job aislado; nunca ejecuta el mapeo en línea.
 */
class IntegrationInboundController extends Controller
{
    public function handle(Request $request, string $provider, IntegrationManager $manager): JsonResponse
    {
        // Proveedor conocido, si no 404.
        abort_unless($manager->provider($provider), 404, 'Proveedor desconocido.');

        $secret = $request->header('X-Siccob-Integration-Secret') ?: $request->query('secret');
        abort_if(blank($secret), 401, 'Falta el secreto de integración.');

        // El secreto identifica exactamente qué configuración (global o de un cliente).
        $integration = Integration::where('provider', $provider)
            ->where('is_active', true)
            ->where('inbound_secret', $secret)
            ->first();
        abort_unless($integration, 401, 'Secreto inválido o integración inactiva.');

        $log = IntegrationLog::create([
            'integration_id' => $integration->id,
            'provider'       => $provider,
            'client_id'      => $integration->client_id,
            'direction'      => 'inbound',
            'event_type'     => (string) ($request->input('event') ?? 'inbound'),
            'status'         => 'pending',
            'payload'        => $request->all(),
        ]);

        RunIntegration::dispatch($log->id);

        return response()->json(['received' => true, 'log_id' => $log->id], 202);
    }
}
