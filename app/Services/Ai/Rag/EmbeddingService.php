<?php

namespace App\Services\Ai\Rag;

use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Http;

/**
 * Genera embeddings con la API de OpenAI (compatible). La llave se resuelve de
 * la configuración del asistente cuando el proveedor es OpenAI; si no, de la
 * variable de entorno OPENAI_API_KEY.
 */
class EmbeddingService
{
    public function model(): string
    {
        return (string) config('ai.embeddings.model', 'text-embedding-3-small');
    }

    /** ¿Hay con qué generar embeddings? */
    public function isOperational(): bool
    {
        return $this->apiKey() !== null;
    }

    private function apiKey(): ?string
    {
        $resolved = AiSettings::resolved();
        if (($resolved['provider'] ?? null) === 'openai' && ! empty($resolved['api_key'])) {
            return $resolved['api_key'];
        }
        return env('OPENAI_API_KEY') ?: null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.embeddings.base_url', 'https://api.openai.com/v1'), '/');
    }

    /**
     * Genera embeddings para un lote de textos. Devuelve un arreglo de vectores
     * (mismo orden que la entrada).
     *
     * @param  string[]  $texts
     * @return array<int,array<int,float>>
     */
    public function embed(array $texts): array
    {
        $key = $this->apiKey();
        if (! $key) {
            throw new \RuntimeException('No hay API key de OpenAI para generar embeddings. Configura OpenAI en el asistente o define OPENAI_API_KEY.');
        }
        if ($texts === []) {
            return [];
        }

        $res = Http::baseUrl($this->baseUrl())
            ->withToken($key)
            ->timeout(120)
            ->acceptJson()
            ->post('/embeddings', [
                'model' => $this->model(),
                'input' => array_values($texts),
            ]);

        if ($res->failed()) {
            throw new \RuntimeException('Error al generar embeddings (' . $res->status() . '): ' . $res->body());
        }

        // Ordenar por index para respetar el orden de entrada.
        $data = collect($res->json('data') ?? [])->sortBy('index')->pluck('embedding')->all();

        return array_map(fn ($v) => array_map('floatval', $v), $data);
    }

    /** Embedding de un solo texto. */
    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0] ?? [];
    }
}
