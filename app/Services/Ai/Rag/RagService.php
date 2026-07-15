<?php

namespace App\Services\Ai\Rag;

use App\Models\AiDocumentChunk;

/**
 * Recuperación semántica sobre la base de conocimiento. Embebe la consulta y
 * calcula similitud coseno contra los fragmentos (filtrados en SQL por alcance
 * ANTES de puntuar, para no recorrer todo el corpus). Adecuado para un corpus de
 * manual (cientos/miles de fragmentos); si creciera mucho, migrar a pgvector.
 */
class RagService
{
    public function __construct(private EmbeddingService $embeddings) {}

    public function isOperational(): bool
    {
        return $this->embeddings->isOperational() && AiDocumentChunk::query()->exists();
    }

    /**
     * Busca los fragmentos más relevantes para una consulta, opcionalmente
     * acotados por alcance.
     *
     * @param  array{collection?:string,catalog_id?:?int,client_id?:?int,audience?:string}  $filters
     *   - collection: 'assistant' | 'support'
     *   - catalog_id (sistema): coincide con ese id O con los globales (catalog_id NULL)
     *   - client_id: coincide con ese cliente O con los globales (client_id NULL)
     *   - audience: 'support' | 'internal'
     * @return array<int,array{heading:?string,content:string,score:float,document:string,audience:string,catalog_id:?int}>
     */
    public function search(string $query, int $topK = 5, float $minScore = 0.15, array $filters = []): array
    {
        $queryVec = $this->embeddings->embedOne($query);
        if ($queryVec === []) {
            return [];
        }

        $base = AiDocumentChunk::query()
            ->with('document:id,title')
            ->select('id', 'ai_document_id', 'heading', 'content', 'embedding', 'audience', 'catalog_id')
            // Solo de documentos activos.
            ->whereHas('document', fn ($q) => $q->where('is_active', true));

        if (! empty($filters['collection'])) {
            $base->where('ai_document_chunks.collection', $filters['collection']);
        }
        // Sistema: el del contexto O los generales (sin sistema).
        if (array_key_exists('catalog_id', $filters)) {
            $sys = $filters['catalog_id'];
            $base->where(function ($w) use ($sys) {
                $w->whereNull('catalog_id');
                if ($sys) $w->orWhere('catalog_id', (int) $sys);
            });
        }
        // Cliente: el del contexto O los globales (sin cliente).
        if (array_key_exists('client_id', $filters)) {
            $cli = $filters['client_id'];
            $base->where(function ($w) use ($cli) {
                $w->whereNull('client_id');
                if ($cli) $w->orWhere('client_id', (int) $cli);
            });
        }
        if (! empty($filters['audience'])) {
            $base->where('audience', $filters['audience']);
        }

        $scored = [];
        $base->chunk(500, function ($chunks) use ($queryVec, &$scored) {
            foreach ($chunks as $c) {
                $scored[] = [
                    'heading'    => $c->heading,
                    'content'    => $c->content,
                    'score'      => self::cosine($queryVec, $c->embedding ?? []),
                    'document'   => $c->document?->title ?? '',
                    'audience'   => $c->audience ?? 'support',
                    'catalog_id' => $c->catalog_id,
                ];
            }
        });

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_filter(
            array_slice($scored, 0, $topK),
            fn ($r) => $r['score'] >= $minScore,
        ));
    }

    /** Similitud coseno entre dos vectores. */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}
