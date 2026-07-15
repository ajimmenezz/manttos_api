<?php

namespace App\Services\Ai\Chat;

use App\Services\Ai\Chat\Contracts\ChatProvider;
use App\Services\Ai\Tools\ToolRegistry;

/**
 * Resuelve el proveedor de chat a partir de la configuración efectiva
 * (App\Services\Ai\AiSettings::resolved()). Cambiar de proveedor/modelo es solo
 * cambiar la configuración — aquí no hay lógica por-cliente.
 */
class ChatProviderFactory
{
    public static function make(array $resolved, ToolRegistry $registry): ChatProvider
    {
        $baseUrl = (string) ($resolved['base_url'] ?? '');
        $apiKey  = $resolved['api_key'] ?? null;
        $model   = (string) ($resolved['model'] ?? '');

        return match ($resolved['api_style'] ?? 'openai') {
            'anthropic' => new AnthropicChatProvider($baseUrl, $apiKey, $model, $registry),
            default     => new OpenAiCompatibleChatProvider($baseUrl, $apiKey, $model, $registry),
        };
    }
}
