<?php

namespace App\Services\Ai\Vision;

use App\Models\AppSetting;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Http;

/**
 * Cliente multimodal de un solo tiro (imágenes + texto → texto), PROVIDER-AGNOSTIC,
 * en el mismo estilo raw-HTTP que los proveedores de chat (OpenAiCompatibleChatProvider
 * / AnthropicChatProvider). No usa herramientas ni loop de agente: es una llamada
 * directa que devuelve el texto del modelo. Reutiliza la configuración efectiva del
 * asistente (AiSettings::resolved()); opcionalmente un modelo de visión distinto vía
 * app_settings['ai_vision_model'] (p. ej. gpt-4o para ver, aunque el chat use otro).
 *
 * REQUISITO: el modelo configurado debe ser multimodal. OpenAI gpt-4o / gpt-4o-mini y
 * cualquier Claude lo son; DeepSeek y el Ollama por defecto (qwen) NO. Si el proveedor
 * rechaza la imagen, se propaga un error accionable.
 */
class VisionClient
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** ¿Hay configuración utilizable para visión? (proveedor + key salvo Ollama local). */
    public function isOperational(): bool
    {
        $r = AiSettings::resolved();
        if (! ($r['enabled'] ?? false)) {
            return false;
        }
        // Ollama local no requiere key; el resto sí.
        return ! empty($r['local']) || ! empty($r['api_key']);
    }

    /**
     * Ejecuta una consulta con imágenes.
     *
     * @param  array<int,array{mime:string,data:string}>  $images  data = base64 (sin prefijo data:)
     * @param  string  $system  prompt de sistema
     * @param  string  $prompt  instrucción del turno de usuario
     * @param  int     $maxTokens
     * @return array{text:string, usage:array{input:int,output:int}, resolved:array}
     */
    public function analyze(array $images, string $system, string $prompt, int $maxTokens = 2048): array
    {
        $resolved = AiSettings::resolved();
        $model    = $this->visionModel($resolved);
        $apiStyle = $resolved['api_style'] ?? 'openai';
        $baseUrl  = rtrim((string) ($resolved['base_url'] ?? ''), '/');
        $apiKey   = $resolved['api_key'] ?? null;

        if ($baseUrl === '') {
            throw new \RuntimeException('La IA no está configurada (sin base_url). Configúrala en Ajustes → Asistente IA.');
        }

        [$text, $usage] = $apiStyle === 'anthropic'
            ? $this->callAnthropic($baseUrl, $apiKey, $model, $system, $prompt, $images, $maxTokens)
            : $this->callOpenAi($baseUrl, $apiKey, $model, $system, $prompt, $images, $maxTokens);

        // El modelo de visión puede diferir del de chat: refleja el usado en el log.
        $resolved['model'] = $model;

        return ['text' => $text, 'usage' => $usage, 'resolved' => $resolved];
    }

    /** Modelo de visión: override opcional o el resuelto del asistente. */
    private function visionModel(array $resolved): string
    {
        $override = trim((string) (AppSetting::allAsMap(AppSetting::DEFAULT_TENANT)['ai_vision_model'] ?? ''));
        return $override !== '' ? $override : (string) ($resolved['model'] ?? '');
    }

    /** OpenAI-compatible (/chat/completions): imagen como data-URI en image_url. */
    private function callOpenAi(string $baseUrl, ?string $apiKey, string $model, string $system, string $prompt, array $images, int $maxTokens): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']],
            ];
        }

        $payload = [
            'model'      => $model,
            'messages'   => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $content],
            ],
            'max_tokens' => $maxTokens,
            'stream'     => false,
        ];

        $req = Http::baseUrl($baseUrl)->timeout(120)->acceptJson();
        if (! empty($apiKey)) {
            $req = $req->withToken($apiKey);
        }

        $res = $req->post('/chat/completions', $payload);
        if ($res->failed()) {
            throw new \RuntimeException('Error del proveedor de visión (' . $res->status() . '): ' . $res->body());
        }

        $usage = $res->json('usage') ?? [];
        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            [
                'input'  => (int) ($usage['prompt_tokens'] ?? 0),
                'output' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    /** Anthropic Messages API: imagen como bloque image/base64. */
    private function callAnthropic(string $baseUrl, ?string $apiKey, string $model, string $system, string $prompt, array $images, int $maxTokens): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $img) {
            $content[] = [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']],
            ];
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ];

        $res = Http::baseUrl($baseUrl)
            ->timeout(120)
            ->withHeaders([
                'x-api-key'         => $apiKey ?? '',
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post('/v1/messages', $payload);

        if ($res->failed()) {
            throw new \RuntimeException('Error del proveedor de visión (' . $res->status() . '): ' . $res->body());
        }

        $text = '';
        foreach ($res->json('content') ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $usage = $res->json('usage') ?? [];

        return [
            trim($text),
            [
                'input'  => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
            ],
        ];
    }
}
