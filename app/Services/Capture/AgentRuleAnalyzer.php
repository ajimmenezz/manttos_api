<?php

namespace App\Services\Capture;

use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Evalúa una regla de comportamiento del agente de captación con la MISMA IA
 * (provider-agnostic, estilo raw-HTTP como CaptureAgent): devuelve un puntaje 0-100
 * + fortalezas / problemas / sugerencias + una versión mejorada opcional. Es
 * orientativo (nunca bloquea guardar). Ver [[CaptureAgentRule]].
 */
class AgentRuleAnalyzer
{
    /**
     * @param  array{scope?:string,title?:?string,instruction:string,example_good?:?string,example_bad?:?string,scope_label?:?string}  $rule
     * @return array{score:int,verdict:string,strengths:array,issues:array,suggestions:array,improved_instruction:?string}
     */
    public function analyze(array $rule): array
    {
        $resolved = AiSettings::resolved();
        if (! $resolved['enabled'] || empty($resolved['model']) || (! $resolved['local'] && empty($resolved['api_key']))) {
            throw new \RuntimeException('La IA no está configurada. Actívala en Ajustes → Asistente IA para evaluar reglas.');
        }

        [$content, $usage] = $this->complete(self::SYSTEM, $this->prompt($rule), $resolved);
        $data = $this->parseJson((string) $content) ?? [];

        $score = max(0, min(100, (int) ($data['score'] ?? 0)));

        AiUsageLogger::log('rule-review', $resolved, $usage, [
            'prompt' => 'Evaluación de regla del agente',
            'reply'  => 'Puntaje ' . $score,
        ]);

        $clean = fn ($v) => collect((array) $v)
            ->map(fn ($x) => trim((string) $x))->filter()->values()->all();

        return [
            'score'                => $score,
            'verdict'              => $this->verdict($score),
            'strengths'            => $clean($data['strengths'] ?? []),
            'issues'               => $clean($data['issues'] ?? []),
            'suggestions'          => $clean($data['suggestions'] ?? []),
            'improved_instruction' => trim((string) ($data['improved_instruction'] ?? '')) ?: null,
        ];
    }

    private function verdict(int $score): string
    {
        return match (true) {
            $score >= 70 => 'good',   // verde: buena regla, lista para usar
            $score >= 45 => 'fair',   // ámbar: aceptable, mejorable
            default      => 'weak',   // rojo: conviene reforzarla
        };
    }

    private function prompt(array $rule): string
    {
        $payload = json_encode([
            'alcance'         => $rule['scope_label'] ?? ($rule['scope'] ?? 'global'),
            'titulo'          => $rule['title'] ?? null,
            'instruccion'     => $rule['instruction'] ?? '',
            'ejemplo_correcto'=> $rule['example_good'] ?? null,
            'ejemplo_a_evitar'=> $rule['example_bad'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        return "Evalúa esta REGLA que un administrador definió para el agente:\n{$payload}\n\n"
            . "Devuelve EXCLUSIVAMENTE un JSON válido (sin texto alrededor, sin ```), con esta forma:\n"
            . '{'
            . '"score": number,               // 0-100 según la rúbrica'
            . '"strengths": string[],          // 1-3 cosas que la regla hace bien (vacío si no hay)'
            . '"issues": string[],             // 1-3 problemas o riesgos (ambigüedad, contradicción, etc.)'
            . '"suggestions": string[],        // 1-3 mejoras concretas y accionables'
            . '"improved_instruction": string  // una reescritura mejorada de la instrucción, o "" si ya está muy bien'
            . '}';
    }

    /**
     * Llama al modelo pidiendo JSON. OpenAI-compatible: response_format json_object
     * (salvo Ollama local). Anthropic: vía su proveedor. Igual que CaptureAgent.
     *
     * @return array{0:string,1:array{input:int,output:int}}
     */
    private function complete(string $system, string $prompt, array $resolved): array
    {
        if (($resolved['api_style'] ?? 'openai') === 'anthropic') {
            $provider = ChatProviderFactory::make($resolved, ToolRegistry::make());
            $res = $provider->chat([['role' => 'user', 'content' => $prompt]], [], $system);
            return [(string) ($res['content'] ?? ''), [
                'input'  => (int) ($res['usage']['input'] ?? 0),
                'output' => (int) ($res['usage']['output'] ?? 0),
            ]];
        }

        $payload = [
            'model'       => $resolved['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 700,
            'stream'      => false,
        ];
        if (empty($resolved['local'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $req = Http::baseUrl(rtrim((string) ($resolved['base_url'] ?: 'https://api.openai.com/v1'), '/'))
            ->timeout(60)->acceptJson();
        if (! empty($resolved['api_key'])) {
            $req = $req->withToken($resolved['api_key']);
        }

        $res = $req->post('/chat/completions', $payload);
        if ($res->failed()) {
            throw new \RuntimeException('IA (' . $res->status() . '): ' . $res->body());
        }

        $usage = $res->json('usage') ?? [];
        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }

    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    private const SYSTEM = <<<'PROMPT'
    Eres un experto en diseñar instrucciones (prompts) para agentes de IA de atención al cliente.
    El agente evaluado CAPTA reportes de mantenimiento de sistemas de seguridad electrónica (CCTV,
    control de acceso, alarmas, detección de incendio) por WhatsApp/Telegram: identifica sitio,
    sistema y descripción, da soporte de 1er nivel y levanta el evento.

    Calificas una REGLA de comportamiento que un administrador definió para ese agente. Evalúa:
    - Claridad: ¿es inequívoca y fácil de seguir?
    - Especificidad/accionabilidad: ¿dice concretamente qué hacer o evitar?
    - Utilidad: ¿mejora de verdad las respuestas del agente?
    - Seguridad: ¿evita contradecir buenas prácticas (no inventar datos, no prometer tiempos, etc.)?
    - Alcance: ¿es coherente con su alcance (global/línea/sistema)?

    RÚBRICA de "score" (calibra para NO ser ni muy duro ni regalar 100):
    - 85-100: excelente, precisa y bien ejemplificada. Reserva >95 solo para reglas excepcionales.
    - 70-84: buena y aplicable tal cual (la mayoría de reglas claras y accionables caen aquí).
    - 45-69: aceptable pero mejorable (algo vaga, sin ejemplo, o de alcance dudoso).
    - 0-44: débil: ambigua, trivial, contradictoria o no accionable.

    Sé constructivo y breve. Responde en español. Devuelve SOLO el JSON pedido.
    PROMPT;
}
