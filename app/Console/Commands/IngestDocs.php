<?php

namespace App\Console\Commands;

use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Services\Ai\Rag\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ingesta el manual/guías a la base de conocimiento del asistente (RAG):
 * recorre las carpetas configuradas (config/ai.rag.sources), extrae el texto de
 * .md y .tsx, lo trocea, genera embeddings con OpenAI y lo guarda. Idempotente:
 * re-ingerir un archivo reemplaza su documento.
 *
 *   php artisan ai:ingest-docs
 */
class IngestDocs extends Command
{
    protected $signature = 'ai:ingest-docs {--fresh : Borra toda la base de conocimiento antes de ingerir}';
    protected $description = 'Ingesta manuales/guías al RAG del asistente (extrae texto, embebe y guarda).';

    public function handle(EmbeddingService $embeddings): int
    {
        if (! $embeddings->isOperational()) {
            $this->error('No hay API key de OpenAI para embeddings. Configura OpenAI en el asistente o define OPENAI_API_KEY.');
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            AiDocument::query()->delete();
            $this->warn('Base de conocimiento vaciada.');
        }

        $files = $this->collectFiles();
        if ($files === []) {
            $this->warn('No se encontraron archivos .md/.tsx en las carpetas configuradas.');
            return self::SUCCESS;
        }

        $this->info(count($files) . ' archivo(s) a ingerir. Modelo: ' . $embeddings->model());
        $totalChunks = 0;

        foreach ($files as $file) {
            $text = $this->extractText($file);
            $chunks = $this->chunk($text);
            if ($chunks === []) {
                $this->line("  · (vacío) " . $this->rel($file));
                continue;
            }

            // Embeddings por lotes.
            $vectors = [];
            foreach (array_chunk($chunks, 96) as $batch) {
                $vectors = array_merge($vectors, $embeddings->embed(array_column($batch, 'content')));
            }

            DB::transaction(function () use ($file, $chunks, $vectors, $embeddings, &$totalChunks) {
                AiDocument::where('source', $this->rel($file))->delete(); // idempotente

                $doc = AiDocument::create([
                    'title'        => $this->titleFor($file),
                    'source'       => $this->rel($file),
                    'kind'         => Str::endsWith($file, '.md') ? 'manual' : 'guia',
                    'chunks_count' => count($chunks),
                    'collection'   => AiDocument::COLLECTION_ASSISTANT,
                    'audience'     => AiDocument::AUDIENCE_INTERNAL,
                    'status'       => 'ready',
                ]);

                foreach ($chunks as $i => $c) {
                    AiDocumentChunk::create([
                        'ai_document_id'  => $doc->id,
                        'idx'             => $i,
                        'heading'         => $c['heading'] ?? null,
                        'content'         => $c['content'],
                        'embedding'       => $vectors[$i] ?? [],
                        'embedding_model' => $embeddings->model(),
                        'collection'      => AiDocument::COLLECTION_ASSISTANT,
                        'audience'        => AiDocument::AUDIENCE_INTERNAL,
                    ]);
                }
                $totalChunks += count($chunks);
            });

            $this->line("  ✓ " . $this->rel($file) . " (" . count($chunks) . " fragmentos)");
        }

        $this->info("Listo. {$totalChunks} fragmentos en total.");
        return self::SUCCESS;
    }

    /** Recolecta .md y .tsx de las carpetas configuradas. */
    private function collectFiles(): array
    {
        $out = [];
        foreach ((array) config('ai.rag.sources', []) as $dir) {
            $real = realpath($dir);
            if (! $real || ! is_dir($real)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $path = $f->getPathname();
                if (preg_match('/\.(md|tsx)$/i', $path)) {
                    $out[] = $path;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** Extrae texto legible de .md (crudo) o .tsx (nodos de texto entre tags). */
    private function extractText(string $file): string
    {
        $raw = @file_get_contents($file) ?: '';

        if (Str::endsWith(strtolower($file), '.md')) {
            return trim($raw);
        }

        // TSX: quitar imports y comentarios JSX, extraer nodos de texto prosa.
        $raw = preg_replace('/\{\/\*.*?\*\/\}/s', ' ', $raw);
        $raw = preg_replace('/^\s*import .*$/m', '', $raw);

        preg_match_all('/>([^<>{}]+)</u', $raw, $m);
        $lines = [];
        foreach ($m[1] as $t) {
            $t = trim(html_entity_decode($t));
            // Conservar solo prosa real (al menos una palabra de 3+ letras).
            if (mb_strlen($t) >= 3 && preg_match('/\p{L}{3,}/u', $t)) {
                $lines[] = $t;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Trocea el texto en fragmentos de ~chunk_size caracteres, respetando saltos
     * de línea. Recuerda el último encabezado (línea corta) como contexto.
     *
     * @return array<int,array{content:string,heading:?string}>
     */
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
            // Heurística de encabezado: markdown ## o línea corta sin punto final.
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

        // Descartar fragmentos triviales.
        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c['content']) >= 40));
    }

    private function rel(string $file): string
    {
        return str_replace('\\', '/', str_replace(realpath(base_path('..')) ?: '', '', $file));
    }

    private function titleFor(string $file): string
    {
        return Str::of(basename($file))->replace(['.md', '.tsx'], '')->headline()->toString();
    }
}
