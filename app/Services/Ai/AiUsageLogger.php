<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Support\AiPricing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro de consumo de IA fuera del asistente interno (agente de captación,
 * estructuración de la base de conocimiento, etc.) en la MISMA tabla de auditoría
 * `ai_interactions`, etiquetado con `source`, para que el Registro IA muestre el
 * gasto real por área. Best-effort: nunca rompe al que lo llama.
 */
class AiUsageLogger
{
    /**
     * @param  array  $resolved  AiSettings::resolved() (provider/model/price_in/price_out)
     * @param  array{input:int,output:int}  $usage
     * @param  array{user_id?:?int,conversation_id?:?string,prompt?:string,reply?:?string,duration_ms?:int,iterations?:int,actions?:array,status?:string,error?:?string}  $meta
     */
    public static function log(string $source, array $resolved, array $usage, array $meta = []): void
    {
        try {
            $inTok  = (int) ($usage['input']  ?? 0);
            $outTok = (int) ($usage['output'] ?? 0);

            // Sin consumo ni error → no registres ruido (p. ej. IA no configurada).
            if ($inTok === 0 && $outTok === 0 && ($meta['status'] ?? 'ok') === 'ok') {
                return;
            }

            $cost = AiPricing::costFromTokens($inTok, $outTok, (float) ($resolved['price_in'] ?? 0), (float) ($resolved['price_out'] ?? 0));

            AiInteraction::create([
                'source'          => $source,
                'conversation_id' => $meta['conversation_id'] ?? null,
                'user_id'         => $meta['user_id'] ?? null,
                'prompt'          => Str::limit((string) ($meta['prompt'] ?? ''), 2000),
                'reply'           => isset($meta['reply']) ? Str::limit((string) $meta['reply'], 2000) : null,
                'provider'        => $resolved['provider'] ?? null,
                'model'           => $resolved['model'] ?? null,
                'input_tokens'    => $inTok,
                'output_tokens'   => $outTok,
                'cost_usd'        => $cost,
                'price_in'        => $resolved['price_in'] ?? null,
                'price_out'       => $resolved['price_out'] ?? null,
                'duration_ms'     => (int) ($meta['duration_ms'] ?? 0),
                'iterations'      => (int) ($meta['iterations'] ?? 1),
                'actions'         => $meta['actions'] ?? null,
                'status'          => $meta['status'] ?? 'ok',
                'error'           => $meta['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiUsageLogger: no se pudo registrar el consumo', ['source' => $source, 'error' => $e->getMessage()]);
        }
    }
}
