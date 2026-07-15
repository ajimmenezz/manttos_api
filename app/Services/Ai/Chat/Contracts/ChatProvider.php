<?php

namespace App\Services\Ai\Chat\Contracts;

/**
 * Proveedor de chat con function calling. Abstrae la diferencia entre la API de
 * Anthropic y las compatibles con OpenAI (OpenAI, DeepSeek, Ollama local). El
 * agent loop (App\Services\Ai\Chat\Agent) habla SIEMPRE en formato neutral y el
 * proveedor traduce a/desde el formato de su API.
 *
 * Formato NEUTRAL de mensajes (lo que consume/produce el loop):
 *   ['role' => 'user',      'content' => string]
 *   ['role' => 'assistant', 'content' => ?string, 'tool_calls' => [ ['id','name','arguments'(array)] ]]
 *   ['role' => 'tool',      'tool_call_id' => string, 'name' => string, 'content' => string(json)]
 */
interface ChatProvider
{
    /**
     * Una ronda de conversación. Devuelve la respuesta del modelo en formato
     * neutral.
     *
     * @param  array  $messages  historial en formato neutral
     * @param  array  $tools     esquema de herramientas (el proveedor elige su formato)
     * @param  string $system    prompt de sistema
     * @return array{content:?string, tool_calls:array<int,array{id:string,name:string,arguments:array}>, usage:array{input:int,output:int}}
     */
    public function chat(array $messages, array $tools, string $system): array;
}
