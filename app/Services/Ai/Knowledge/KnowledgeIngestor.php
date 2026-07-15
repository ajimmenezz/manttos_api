<?php

namespace App\Services\Ai\Knowledge;

use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Models\AppSetting;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Rag\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingesta de la BASE DE CONOCIMIENTO DE SOPORTE (por sistema/cliente). Para un
 * AiDocument recién creado ('processing'): extrae el texto del archivo (PDF/Word/
 * texto), opcionalmente lo ESTRUCTURA con un modelo potente (síntoma→causa→pasos,
 * FAQ, criterios de respuesta), lo trocea, genera embeddings y guarda los
 * fragmentos con su alcance denormalizado. Idempotente: reingerir un documento
 * reemplaza sus fragmentos.
 *
 * Los EMBEDDINGS (indexar) usan un modelo barato (OpenAI); la ESTRUCTURACIÓN (una
 * sola vez por documento) es donde conviene un modelo potente — configurable en
 * app_settings['ai_ingest_model'], si no cae al modelo del asistente.
 */
class KnowledgeIngestor
{
    /** Límite de texto para intentar estructurar; por encima se trocea crudo (evita context/costo). */
    private const STRUCTURE_MAX_CHARS = 45000;

    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * Procesa un documento completo. Actualiza su estado (ready|failed) y su
     * conteo de fragmentos. `$rawTextOverride` permite ingerir texto pegado sin
     * archivo. Nunca lanza: registra el error en el documento.
     */
    public function ingestDocument(AiDocument $doc, ?string $rawTextOverride = null): void
    {
        @set_time_limit(0);

        try {
            $doc->update(['status' => 'processing', 'error' => null]);

            if (! $this->embeddings->isOperational()) {
                throw new \RuntimeException('No hay API key de OpenAI para generar embeddings. Configura OpenAI en el asistente o define OPENAI_API_KEY.');
            }

            $raw = $rawTextOverride !== null && trim($rawTextOverride) !== ''
                ? $rawTextOverride
                : $this->extractFromDocument($doc);

            $raw = trim($raw);
            if ($raw === '') {
                throw new \RuntimeException('El documento no tiene texto legible: suele ser un PDF escaneado (solo imágenes, sin capa de texto) o protegido. Súbelo como Word (.docx), pega el texto, o usa un PDF con texto seleccionable.');
            }

            [$text, $structured] = $this->maybeStructure($raw, $doc);

            $chunks = $this->chunk($text);
            if ($chunks === []) {
                throw new \RuntimeException('El documento no produjo fragmentos de conocimiento.');
            }

            // Embeddings por lotes.
            $vectors = [];
            foreach (array_chunk($chunks, 96) as $batch) {
                $vectors = array_merge($vectors, $this->embeddings->embed(array_column($batch, 'content')));
            }

            DB::transaction(function () use ($doc, $chunks, $vectors, $structured, $text) {
                $doc->chunks()->delete(); // idempotente

                foreach ($chunks as $i => $c) {
                    AiDocumentChunk::create([
                        'ai_document_id'  => $doc->id,
                        'idx'             => $i,
                        'heading'         => $c['heading'] ?? null,
                        'content'         => $c['content'],
                        'embedding'       => $vectors[$i] ?? [],
                        'embedding_model' => $this->embeddings->model(),
                        'collection'      => AiDocument::COLLECTION_SUPPORT,
                        'catalog_id'      => $doc->catalog_id,
                        'client_id'       => $doc->client_id,
                        'audience'        => $doc->audience,
                    ]);
                }

                $doc->update([
                    'status'       => 'ready',
                    'chunks_count' => count($chunks),
                    'structured'   => $structured,
                    'body_md'      => $text, // artículo legible (estructurado o crudo) para la KB navegable
                    'error'        => null,
                ]);
            });
        } catch (\Throwable $e) {
            $doc->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }

    // ── Extracción de texto ──────────────────────────────────────────

    private function extractFromDocument(AiDocument $doc): string
    {
        if (! $doc->file_path) {
            throw new \RuntimeException('El documento no tiene archivo ni texto para ingerir.');
        }
        $abs = Storage::disk('local')->path($doc->file_path);
        if (! is_file($abs)) {
            throw new \RuntimeException('No se encontró el archivo del documento.');
        }
        return $this->extractText($abs, $doc->original_filename ?: $abs);
    }

    /** Extrae texto legible de un archivo (PDF, Word .docx, texto). */
    public function extractText(string $path, string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'txt', 'md', 'markdown' => (string) @file_get_contents($path),
            'pdf'                   => $this->extractPdf($path),
            'docx'                  => $this->extractDocx($path),
            'doc'                   => throw new \RuntimeException('El formato .doc antiguo no es compatible. Guarda el archivo como .docx o PDF.'),
            default                 => throw new \RuntimeException("Formato no soportado (.{$ext}). Sube PDF, Word (.docx) o texto (.txt/.md)."),
        };
    }

    private function extractPdf(string $path): string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('Falta la librería de PDF (smalot/pdfparser). Instálala con composer.');
        }

        // Muchos PDF vienen "protegidos" solo con owner-password (restringen imprimir/copiar)
        // pero su texto es legible: ignoramos el cifrado para poder extraerlos.
        $config = new \Smalot\PdfParser\Config();
        $config->setIgnoreEncryption(true);
        $parser = new \Smalot\PdfParser\Parser([], $config);

        try {
            $pdf = $parser->parseFile($path);
            return (string) $pdf->getText();
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'secur') !== false || stripos($msg, 'encrypt') !== false || stripos($msg, 'password') !== false) {
                throw new \RuntimeException('El PDF está protegido con contraseña y no se pudo leer. Quítale la protección (imprímelo o "Guardar como" PDF sin restricciones), o súbelo como Word (.docx) o pega el texto.');
            }
            throw new \RuntimeException('No se pudo leer el PDF: ' . \Illuminate\Support\Str::limit($msg, 160) . '. Prueba subirlo como Word (.docx) o pega el texto.');
        }
    }

    /** Extrae texto de un .docx leyendo word/document.xml del zip (sin dependencias). */
    private function extractDocx(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('No se puede leer el .docx (falta la extensión zip de PHP).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo .docx.');
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        if ($xml === '') {
            return '';
        }
        // Párrafos y saltos → nueva línea; el resto de las etiquetas se elimina.
        $xml = preg_replace('/<w:(p|br|tab)\b[^>]*\/?>/i', "\n", $xml);
        $xml = preg_replace('/<\/w:p>/i', "\n", $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Colapsa líneas en blanco excesivas.
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    // ── Estructuración con modelo potente (una sola vez) ─────────────

    /**
     * Reescribe el texto crudo en conocimiento accionable para soporte. Best-effort:
     * si no hay modelo, el texto es muy largo, o falla, devuelve el crudo.
     *
     * @return array{0:string,1:bool} [texto, ¿se estructuró?]
     */
    private function maybeStructure(string $raw, AiDocument $doc): array
    {
        $resolved = AiSettings::resolved();
        $enabled = ($resolved['enabled'] ?? false) && ! empty($resolved['model'])
            && ($resolved['local'] || ! empty($resolved['api_key']));

        if (! $enabled || mb_strlen($raw) > self::STRUCTURE_MAX_CHARS) {
            return [$raw, false];
        }

        // Modelo de ingesta (potente) si está configurado; si no, el del asistente.
        $ingestModel = trim((string) (AppSetting::allAsMap()['ai_ingest_model'] ?? ''));
        if ($ingestModel !== '') {
            $resolved['model'] = $ingestModel;
        }

        $system = $this->structuringSystemPrompt($doc);
        try {
            [$out, $usage] = $this->complete($raw, $system, $resolved);
        } catch (\Throwable) {
            return [$raw, false];
        }

        // Registra el consumo de la estructuración (para el Registro IA / costo).
        \App\Services\Ai\AiUsageLogger::log('ingest', $resolved, $usage, [
            'user_id' => $doc->created_by,
            'prompt'  => 'Estructuración de manual: ' . $doc->title,
            'reply'   => $out,
        ]);

        // Algunos modelos envuelven TODO el markdown en un bloque de código
        // (```markdown ... ```); hay que quitarlo o el visor lo pinta como código literal.
        $out = $this->stripCodeFence(trim($out));
        // Sanidad: si el modelo devolvió algo demasiado corto, quédate con el crudo.
        return mb_strlen($out) >= 80 ? [$out, true] : [$raw, false];
    }

    private function structuringSystemPrompt(AiDocument $doc): string
    {
        $system = optional($doc->system)->label ?: 'el sistema';
        $audience = $doc->audience === AiDocument::AUDIENCE_INTERNAL
            ? "El material son CRITERIOS INTERNOS de cómo el equipo debe responder al cliente; conserva el criterio y el tono recomendado."
            : "El material es para SOPORTE DE 1er NIVEL al cliente final.";

        return <<<PROMPT
        Eres un ingeniero senior de soporte de sistemas de seguridad electrónica
        (CCTV, detección de incendio, control de acceso, etc.). {$audience}

        Toma el MANUAL/DOCUMENTO CRUDO (posiblemente extraído de un PDF/Word, con ruido
        de formato) sobre "{$system}" y reescríbelo como CONOCIMIENTO ACCIONABLE para
        recuperación (RAG). Organízalo en markdown con encabezados claros, agrupando:

        - "Síntomas y solución": por cada falla común → síntoma, causa probable y PASOS
          concretos de verificación/solución, en orden.
        - "Preguntas frecuentes": Q&A breves.
        - "Criterios de respuesta": cómo comunicar al cliente (tono, qué prometer, cuándo escalar).

        Reglas:
        - Conserva TODO dato técnico útil (modelos, valores, tiempos, referencias). NO inventes.
        - Frases cortas y directas, pensadas para que un agente guíe al cliente por chat.
        - Omite portadas, índices, pies de página y ruido de formato.
        - Responde SOLO con el markdown, sin explicaciones alrededor.
        PROMPT;
    }

    /**
     * Una llamada de completado de texto (no JSON) reutilizando el stack del
     * asistente: Anthropic vía su proveedor; compatibles-OpenAI vía HTTP directo.
     *
     * @return array{0:string,1:array{input:int,output:int}} [contenido, consumo]
     */
    private function complete(string $userText, string $system, array $resolved): array
    {
        if (($resolved['api_style'] ?? 'openai') === 'anthropic') {
            $provider = ChatProviderFactory::make($resolved, []);
            $res = $provider->chat([['role' => 'user', 'content' => $userText]], [], $system);
            return [(string) ($res['content'] ?? ''), [
                'input'  => (int) ($res['usage']['input'] ?? 0),
                'output' => (int) ($res['usage']['output'] ?? 0),
            ]];
        }

        $payload = [
            'model'       => $resolved['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userText],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 4000,
            'stream'      => false,
        ];

        $req = Http::baseUrl(rtrim((string) ($resolved['base_url'] ?: 'https://api.openai.com/v1'), '/'))
            ->timeout(180)->acceptJson();
        if (! empty($resolved['api_key'])) {
            $req = $req->withToken($resolved['api_key']);
        }

        $res = $req->post('/chat/completions', $payload);
        if ($res->failed()) {
            throw new \RuntimeException('IA de ingesta (' . $res->status() . '): ' . $res->body());
        }
        $usage = $res->json('usage') ?? [];
        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }

    /** Quita una envoltura de bloque de código (```lang … ```) que abarque todo el texto. */
    private function stripCodeFence(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^```[a-zA-Z0-9]*\s*\R(.*?)\R?```$/s', $s, $m)) {
            return trim($m[1]);
        }
        return $s;
    }

    // ── Troceo (misma heurística que el ingestador de carpetas) ──────

    /** @return array<int,array{content:string,heading:?string}> */
    private function chunk(string $text): array
    {
        $max   = (int) config('ai.rag.chunk_size', 1200);
        $lines = preg_split('/\n+/', $text);

        $chunks  = [];
        $buffer  = '';
        $heading = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+/', $line) || (mb_strlen($line) <= 60 && ! Str::endsWith($line, '.'))) {
                $heading = ltrim($line, '# ');
            }

            if (mb_strlen($buffer) + mb_strlen($line) + 1 > $max && $buffer !== '') {
                $chunks[] = ['content' => trim($buffer), 'heading' => $heading];
                $buffer = '';
            }
            $buffer .= $line . "\n";
        }
        if (trim($buffer) !== '') {
            $chunks[] = ['content' => trim($buffer), 'heading' => $heading];
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c['content']) >= 40));
    }
}
