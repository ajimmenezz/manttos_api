<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Rag\RagService;
use App\Services\Ai\Tools\Contracts\Tool;

/**
 * Búsqueda semántica en el manual/guías del sistema (RAG). Permite a la IA
 * responder "cómo se hace X" o "qué es Y" con el procedimiento real documentado,
 * en vez de inventarlo. El manual es el mismo para todos (no depende del usuario).
 */
class SearchManualTool implements Tool
{
    public function name(): string
    {
        return 'buscar_en_manual';
    }

    public function description(): string
    {
        return 'Busca en el manual y las guías del sistema para responder cómo funciona algo o cómo '
            . 'realizar un procedimiento (p. ej. "¿cómo registro una actividad?", "¿qué es un directorio?"). '
            . 'Devuelve fragmentos relevantes de la documentación. Úsala para preguntas de uso/procedimiento.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'La pregunta o tema a buscar en el manual.'],
            ],
            'required' => ['query'],
        ];
    }

    public function mutating(): bool
    {
        return false;
    }

    public function confirm(): bool
    {
        return false;
    }

    public function handle(array $args, User $user): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'Falta la consulta a buscar.'];
        }

        /** @var RagService $rag */
        $rag = app(RagService::class);

        if (! $rag->isOperational()) {
            return ['error' => 'La base de conocimiento no está disponible (falta ingerir el manual o configurar OpenAI para embeddings).'];
        }

        // Solo el corpus del asistente interno (manual/guías), no la base de
        // conocimiento de soporte por sistema (esa es para el agente de captación).
        $results = $rag->search($query, topK: 5, filters: ['collection' => \App\Models\AiDocument::COLLECTION_ASSISTANT]);
        if ($results === []) {
            return ['results' => [], 'note' => 'No encontré nada relevante en el manual para esa consulta.'];
        }

        return [
            'results' => array_map(fn ($r) => [
                'documento' => $r['document'],
                'seccion'   => $r['heading'],
                'contenido' => $r['content'],
                'relevancia'=> round($r['score'], 3),
            ], $results),
        ];
    }
}
