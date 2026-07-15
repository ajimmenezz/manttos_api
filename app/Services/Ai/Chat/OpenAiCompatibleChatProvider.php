<?php

namespace App\Services\Ai\Chat;

use App\Services\Ai\Chat\Contracts\ChatProvider;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor para APIs compatibles con OpenAI: OpenAI, DeepSeek y Ollama local.
 * Usa POST {base_url}/chat/completions con el arreglo `tools` estilo OpenAI.
 * Para Ollama no se envía API key.
 */
class OpenAiCompatibleChatProvider implements ChatProvider
{
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
            'messages'   => $this->toWireMessages($messages, $system),
            'tools'      => $this->registry->toOpenAiSchema(),
            'tool_choice' => 'auto',
            'max_tokens' => 2048,
            'stream'     => false,
        ];

        $req = Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(120)->acceptJson();
        if (! empty($this->apiKey)) {
            $req = $req->withToken($this->apiKey);
        }

        $res = $req->post('/chat/completions', $payload);

        if ($res->failed()) {
            throw new \RuntimeException('Error del proveedor de IA (' . $res->status() . '): ' . $res->body());
        }

        $msg   = $res->json('choices.0.message') ?? [];
        $usage = $res->json('usage') ?? [];

        $toolCalls = [];
        foreach ($msg['tool_calls'] ?? [] as $tc) {
            $args = $tc['function']['arguments'] ?? '{}';
            $toolCalls[] = [
                'id'        => $tc['id'] ?? uniqid('call_'),
                'name'      => $tc['function']['name'] ?? '',
                'arguments' => is_array($args) ? $args : (json_decode($args, true) ?: []),
            ];
        }

        return [
            'content'    => $msg['content'] ?? null,
            'tool_calls' => $toolCalls,
            'usage'      => [
                'input'  => (int) ($usage['prompt_tokens'] ?? 0),
                'output' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    /** Traduce mensajes neutrales al formato OpenAI (incluye system al inicio). */
    private function toWireMessages(array $messages, string $system): array
    {
        $wire = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $m) {
            switch ($m['role']) {
                case 'user':
                    $wire[] = ['role' => 'user', 'content' => (string) ($m['content'] ?? '')];
                    break;

                case 'assistant':
                    $entry = ['role' => 'assistant', 'content' => $m['content'] ?? ''];
                    if (! empty($m['tool_calls'])) {
                        $entry['tool_calls'] = array_map(fn ($tc) => [
                            'id'       => $tc['id'],
                            'type'     => 'function',
                            'function' => [
                                // Forzar objeto JSON: sin args, json_encode([]) daría "[]"
                                // (arreglo) y el proveedor lo rechaza como argumentos inválidos.
                                'name'      => $tc['name'],
                                'arguments' => json_encode((object) ($tc['arguments'] ?? [])),
                            ],
                        ], $m['tool_calls']);
                    }
                    $wire[] = $entry;
                    break;

                case 'tool':
                    $wire[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $m['tool_call_id'],
                        'content'      => (string) ($m['content'] ?? ''),
                    ];
                    break;
            }
        }

        return $wire;
    }
}
