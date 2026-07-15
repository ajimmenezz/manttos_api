<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook público de WhatsApp Cloud API (Meta) para captación de eventos.
 *   GET  /api/whatsapp/webhook  → handshake de verificación (hub.challenge).
 *   POST /api/whatsapp/webhook  → recepción de mensajes entrantes.
 */
class WhatsAppWebhookController extends Controller
{
    /** Handshake de verificación de Meta. */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token && $token === config('whatsapp.verify_token')) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Recepción de eventos. Responde 200 rápido; procesa best-effort. */
    public function handle(Request $request, WhatsAppWebhookProcessor $processor): Response
    {
        try {
            $processor->process($request->all());
        } catch (\Throwable $e) {
            report($e);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
