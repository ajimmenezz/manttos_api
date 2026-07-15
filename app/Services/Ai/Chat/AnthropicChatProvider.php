<?php

namespace App\Services\Ai\Chat;

use App\Services\Ai\Chat\Contracts\ChatProvider;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor para la API de Anthropic (Messages API). Usa POST /v1/messages con
 * el arreglo `tools` estilo Anthropic (input_schema) y bloques tool_use /
 * tool_result. No se envían temperature/thinking para máxima compatibilidad
 * entre modelos (Haiku 4.5, Sonnet 5, Opus 4.8).
 */
class AnthropicChatProvider implements ChatProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private string $model,
        private ToolRegistry $registry,
    ) {}

    public function chat(array $messages, array $tools, string $system): array
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => 2048,
            'system'     => $system,
            'messages'   => $this->toWireMessages($messages),
            'tools'      => $this->registry->toAnthropicSchema(),
        ];

        $res = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout(120)
            ->withHeaders([
                'x-api-key'         => $this->apiKey ?? '',
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post('/v1/messages', $payload);

        if ($res->failed()) {
            throw new \RuntimeException('Error del proveedor de IA (' . $res->status() . '): ' . $res->body());
        }

        $content = $res->json('content') ?? [];
        $usage   = $res->json('usage') ?? [];

        $text      = null;
        $toolCalls = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = trim(($text ?? '') . $block['text']);
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id'        => $block['id'],
                    'name'      => $block['name'],
                    'arguments' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return [
            'content'    => $text,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'input'  => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
            ],
        ];
    }

    /** Traduce mensajes neutrales al formato Anthropic (tool_use / tool_result). */
    private function toWireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $m) {
            switch ($m['role']) {
                case 'user':
                    $wire[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) ($m['content'] ?? '')]]];
                    break;

                case 'assistant':
                    $blocks = [];
                    if (! empty($m['content'])) {
                        $blocks[] = ['type' => 'text', 'text' => (string) $m['content']];
                    }
                    foreach ($m['tool_calls'] ?? [] as $tc) {
                        $blocks[] = [
                            'type'  => 'tool_use',
                            'id'    => $tc['id'],
                            'name'  => $tc['name'],
                            'input' => (object) ($tc['arguments'] ?? []),
                        ];
                    }
                    $wire[] = ['role' => 'assistant', 'content' => $blocks];
                    break;

                case 'tool':
                    // Anthropic entrega los resultados de herramienta como turno de usuario.
                    $wire[] = ['role' => 'user', 'content' => [[
                        'type'        => 'tool_result',
                        'tool_use_id' => $m['tool_call_id'],
                        'content'     => (string) ($m['content'] ?? ''),
                    ]]];
                    break;
            }
        }

        return $wire;
    }
}
