<?php

namespace App\Services\Ai;

use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Empareja una tarea de ADIST con un dispositivo del directorio cuando NO hay
 * identificador comun.
 *
 * Por que hace falta: ADIST no comparte llave con el directorio. Se midio sobre una
 * solicitud real (10001306, Videovigilancia): de 41 tareas, 11 declaran numero de serie
 * y NINGUNA de esas series existe en el directorio — ADIST usa codigos tipo `K59547451`
 * y el directorio guarda `20210511AAWRF80237672`. La unica senal util es la UBICACION
 * escrita en el titulo ("... - RESTAURANTE SEASALT") contra el nombre del dispositivo.
 *
 * Reparto de trabajo, a proposito:
 *   - La LISTA CORTA la arma `AdistImportService::rankCandidates()`, que es texto puro:
 *     gratis, instantaneo y suficiente para dejar la respuesta correcta DENTRO de la
 *     lista casi siempre.
 *   - La IA solo ELIGE entre esos pocos. Es donde el texto se queda corto: "RESTAURANTE
 *     SEASALT" contra "Restaurante Seaside" son restaurantes DISTINTOS, y "VIALIDAD
 *     BUNGALO 12" no es el bungalo 9 ni el 14, aunque compartan casi todas las palabras.
 *
 * NUNCA asocia sola: devuelve sugerencia + confianza + motivo, y la pantalla pide
 * confirmacion. Una actividad colgada del dispositivo equivocado es un dato historico
 * falso que nadie vuelve a revisar.
 */
class AdistDeviceMatcher
{
    /** Tareas por llamada. Suficientes para amortizar el prompt sin que el modelo se pierda. */
    private const BATCH = 12;

    public function isOperational(): bool
    {
        $r = AiSettings::resolved();

        return ($r['enabled'] ?? false) && ! empty($r['model']);
    }

    /**
     * @param  array<int,array{task_id:int, text:string, candidates:array<int,array{id:int,label:string}>}>  $items
     * @return array<int,array{device_id:?int, confidence:int, reason:string}>  indexado por task_id
     */
    public function suggest(array $items): array
    {
        if (! $this->isOperational() || ! $items) return [];

        $resolved = AiSettings::resolved();
        $out = [];

        foreach (array_chunk($items, self::BATCH) as $chunk) {
            // Una tanda que falle no puede tumbar las demas: se devuelve lo que si salio.
            try {
                [$content, $usage] = $this->complete(self::SYSTEM, $this->prompt($chunk), $resolved);
                foreach ($this->parse($content) as $taskId => $pick) {
                    $out[$taskId] = $pick;
                }
                AiUsageLogger::log('adist-match', $resolved, $usage, ['tasks' => count($chunk)]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /** @param array<int,array{task_id:int, text:string, candidates:array}> $chunk */
    private function prompt(array $chunk): string
    {
        $lines = [];
        foreach ($chunk as $it) {
            $cands = [];
            foreach ($it['candidates'] as $c) {
                $cands[] = ['id' => (int) $c['id'], 'nombre' => $c['label']];
            }
            $lines[] = [
                'task_id'    => (int) $it['task_id'],
                'trabajo'    => trim(preg_replace('/\s+/', ' ', $it['text'])),
                'candidatos' => $cands,
            ];
        }

        return json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** @return array<int,array{device_id:?int, confidence:int, reason:string}> */
    private function parse(string $content): array
    {
        // Los modelos suelen envolver el JSON en ```json ... ```
        if (preg_match('/```(?:json)?(.+?)```/s', $content, $m)) {
            $content = $m[1];
        }
        // O anteponer texto: se recorta al primer arreglo.
        $ini = strpos($content, '[');
        $fin = strrpos($content, ']');
        if ($ini === false || $fin === false || $fin < $ini) return [];

        $rows = json_decode(substr($content, $ini, $fin - $ini + 1), true);
        if (! is_array($rows)) return [];

        $out = [];
        foreach ($rows as $r) {
            if (! isset($r['task_id'])) continue;
            $device = $r['device_id'] ?? null;
            $out[(int) $r['task_id']] = [
                'device_id'  => ($device === null || $device === '' || (int) $device === 0) ? null : (int) $device,
                'confidence' => max(0, min(100, (int) ($r['confidence'] ?? 0))),
                'reason'     => mb_substr(trim((string) ($r['reason'] ?? '')), 0, 120),
            ];
        }

        return $out;
    }

    /** @return array{0:string,1:array{input:int,output:int}} */
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

        $req = Http::baseUrl(rtrim((string) ($resolved['base_url'] ?: 'https://api.openai.com/v1'), '/'))
            ->timeout(90)->acceptJson();
        if (! empty($resolved['api_key'])) {
            $req = $req->withToken($resolved['api_key']);
        }

        $res = $req->post('/chat/completions', [
            'model'       => $resolved['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0,   // decision, no redaccion: se quiere repetible
            'max_tokens'  => 1500,
            'stream'      => false,
        ]);

        if ($res->failed()) {
            throw new \RuntimeException('IA (' . $res->status() . '): ' . $res->body());
        }

        $usage = $res->json('usage') ?? [];

        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }

    private const SYSTEM = <<<'PROMPT'
    Trabajas en mantenimiento de sistemas de seguridad electronica (camaras, deteccion de
    incendio, control de acceso) en hoteles.

    Recibes un arreglo JSON. Cada elemento es un trabajo realizado y una lista corta de
    dispositivos del directorio que PODRIAN ser el equipo atendido. Tu tarea: decidir cual
    es, basandote en la UBICACION y el tipo de equipo mencionados en el texto del trabajo.

    Reglas:
    - Elige UNICAMENTE un id de la lista de candidatos de ESE elemento. Nunca inventes ids.
    - Si ninguno corresponde con claridad, responde device_id: null. Es la respuesta
      correcta muchas veces y es preferible a adivinar.
    - Los nombres parecidos NO son el mismo lugar. "SeaSalt" no es "Seaside"; "bungalo 12"
      no es "bungalo 9" ni "bungalo 14"; "nivel 3" no es "nivel 2". Si el texto nombra un
      numero o un nombre propio y ningun candidato lo tiene, responde null.
    - Las palabras genericas (CAMARA, HIKVISION, MANTTO, PREVENTIVO, DOMO, BALA) no
      identifican nada: casi todos los equipos las comparten. Ignoralas al decidir.
    - confidence: 0-100. Usa 90+ solo si la ubicacion coincide de forma inequivoca; 40-70
      si es plausible pero hay ambiguedad; por debajo de 40, mejor null.
    - reason: maximo 12 palabras, en espanol, diciendo QUE lo decidio.

    Responde SOLO un arreglo JSON, sin texto alrededor y sin markdown:
    [{"task_id": 123, "device_id": 456, "confidence": 95, "reason": "coincide restaurante SeaSalt"}]
    PROMPT;
}
