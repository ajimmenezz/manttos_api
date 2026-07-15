<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Request;

/**
 * Webhook público de Telegram (producción). Valida el secreto por header contra
 * el guardado en la línea y entrega el update al pipeline de captación.
 * Ruta: POST /api/telegram/webhook/{channel}
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, Channel $channel, TelegramUpdateProcessor $processor)
    {
        // Telegram reintenta si no recibe 200: respondemos 200 siempre y procesamos best-effort.
        $secret = $channel->metadata['tg_webhook_secret'] ?? null;
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => true]); // ignora silenciosamente updates no autenticados
        }

        if ($channel->isTelegram() && $channel->is_active) {
            try {
                $processor->process($channel, $request->all());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true]);
    }
}
