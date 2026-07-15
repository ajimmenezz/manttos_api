<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiDocument;
use App\Models\Catalog;
use App\Models\Client;
use App\Services\Ai\Knowledge\KnowledgeIngestor;
use App\Services\Ai\Rag\EmbeddingService;
use App\Services\Ai\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base de conocimiento de SOPORTE (collection = 'support'): manuales de operación/
 * mantenimiento/solución y criterios de respuesta, por SISTEMA y opcionalmente por
 * CLIENTE. Subir PDF/Word/texto → se extrae, (opcional) se estructura con un modelo
 * potente, se trocea y se embebe para que el agente de captación dé soporte de 1er
 * nivel. Requiere el permiso `knowledge.manage` (admin/superadmin).
 */
class KnowledgeController extends Controller
{
    private const EXTS = ['pdf', 'docx', 'txt', 'md', 'markdown'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);

        $docs = AiDocument::query()
            ->where('collection', AiDocument::COLLECTION_SUPPORT)
            ->with(['system:id,label', 'client:id,name,short_name', 'author:id,name'])
            ->when($request->filled('catalog_id'), fn ($q) => $q->where('catalog_id', $request->catalog_id))
            ->when($request->filled('client_id'), fn ($q) => (int) $request->client_id === 0
                ? $q->whereNull('client_id')
                : $q->where('client_id', $request->client_id))
            ->when($request->filled('audience'), fn ($q) => $q->where('audience', $request->audience))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->orderByDesc('updated_at')
            ->limit(500)->get();

        return response()->json([
            'documents' => $docs->map(fn ($d) => $this->serialize($d)),
            'embeddings_ready' => app(EmbeddingService::class)->isOperational(),
        ]);
    }

    /** Opciones para los selectores: sistemas y clientes. */
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);

        return response()->json([
            'systems' => Catalog::ofType(Catalog::TYPE_SYSTEM)->get(['id', 'label'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label]),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'short_name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->short_name ?: $c->name]),
        ]);
    }

    public function store(Request $request, KnowledgeIngestor $ingestor): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);

        $data = $request->validate([
            'title'      => 'required|string|max:255',
            'catalog_id' => 'required|exists:catalogs,id',
            'client_id'  => 'nullable|exists:clients,id',
            'audience'   => 'required|in:support,internal',
            'kind'       => 'nullable|string|max:40',
            'file'       => 'nullable|file|max:20480',
            'content'    => 'nullable|string',
        ]);

        // El catálogo debe ser un SISTEMA.
        abort_unless(Catalog::where('id', $data['catalog_id'])->where('type', Catalog::TYPE_SYSTEM)->exists(),
            422, 'El catálogo indicado no es un sistema.');

        $file = $request->file('file');
        $content = trim((string) ($data['content'] ?? ''));
        if (! $file && $content === '') {
            return response()->json(['message' => 'Sube un archivo (PDF/Word/texto) o pega el contenido.'], 422);
        }

        $filePath = null; $originalName = null;
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, self::EXTS, true)) {
                return response()->json(['message' => 'Formato no soportado. Sube PDF, Word (.docx) o texto (.txt/.md).'], 422);
            }
            $originalName = $file->getClientOriginalName();
            $filePath = $file->store('knowledge', 'local'); // storage/app/knowledge/...
        }

        $doc = AiDocument::create([
            'title'             => $data['title'],
            'source'            => 'knowledge:' . uniqid(),
            'kind'              => $data['kind'] ?? 'manual',
            'chunks_count'      => 0,
            'collection'        => AiDocument::COLLECTION_SUPPORT,
            'catalog_id'        => $data['catalog_id'],
            'client_id'         => $data['client_id'] ?? null,
            'audience'          => $data['audience'],
            'original_filename' => $originalName,
            'file_path'         => $filePath,
            'status'            => 'processing',
            'is_active'         => true,
            'created_by'        => $request->user()->id,
        ]);

        // Ingesta síncrona (extrae/estructura/embebe). Documentos de manual son chicos.
        $ingestor->ingestDocument($doc, $file ? null : $content);

        return response()->json([
            'message'  => $doc->fresh()->status === 'ready' ? 'Documento ingerido a la base de conocimiento.' : 'El documento se guardó pero la ingesta tuvo un problema.',
            'document' => $this->serialize($doc->fresh(['system', 'client', 'author'])),
        ], 201);
    }

    public function update(Request $request, AiDocument $document): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);
        $this->assertSupport($document);

        $data = $request->validate([
            'title'      => 'sometimes|required|string|max:255',
            'catalog_id' => 'sometimes|required|exists:catalogs,id',
            'client_id'  => 'nullable|exists:clients,id',
            'audience'   => 'sometimes|required|in:support,internal',
            'kind'       => 'nullable|string|max:40',
            'is_active'  => 'sometimes|boolean',
        ]);

        if (array_key_exists('catalog_id', $data)) {
            abort_unless(Catalog::where('id', $data['catalog_id'])->where('type', Catalog::TYPE_SYSTEM)->exists(),
                422, 'El catálogo indicado no es un sistema.');
        }

        $document->fill($data);
        // client_id puede venir explícito como null.
        if ($request->has('client_id')) $document->client_id = $data['client_id'] ?? null;
        $document->save();

        // Propaga el alcance a los fragmentos (denormalizado para el filtrado del RAG).
        if ($document->wasChanged(['catalog_id', 'client_id', 'audience'])) {
            $document->chunks()->update([
                'catalog_id' => $document->catalog_id,
                'client_id'  => $document->client_id,
                'audience'   => $document->audience,
            ]);
        }

        return response()->json([
            'message'  => 'Documento actualizado.',
            'document' => $this->serialize($document->fresh(['system', 'client', 'author'])),
        ]);
    }

    /** Re-procesa el documento desde su archivo original (re-extrae/estructura/embebe). */
    public function reingest(Request $request, AiDocument $document, KnowledgeIngestor $ingestor): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);
        $this->assertSupport($document);

        if (! $document->file_path) {
            return response()->json(['message' => 'Este documento no tiene archivo para re-procesar. Súbelo de nuevo.'], 422);
        }

        $ingestor->ingestDocument($document);

        return response()->json([
            'message'  => $document->fresh()->status === 'ready' ? 'Documento re-procesado.' : 'La re-ingesta tuvo un problema.',
            'document' => $this->serialize($document->fresh(['system', 'client', 'author'])),
        ]);
    }

    public function destroy(Request $request, AiDocument $document): JsonResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);
        $this->assertSupport($document);

        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->delete(); // los fragmentos caen por FK cascade

        return response()->json(['message' => 'Documento eliminado.']);
    }

    /** Descarga el archivo original. */
    public function download(Request $request, AiDocument $document): StreamedResponse
    {
        abort_unless($request->user()->can('knowledge.manage'), 403);
        $this->assertSupport($document);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename ?: 'documento');
    }

    // ── Lector de la base de conocimiento (navegable, estilo ITSM) ────
    // Para el EQUIPO (no solicitantes): navega/lee los artículos ya publicados.

    /** Lista de artículos publicados (para leer), agrupables por sistema. */
    public function articles(Request $request): JsonResponse
    {
        $this->assertStaff($request);

        $docs = AiDocument::query()
            ->where('collection', AiDocument::COLLECTION_SUPPORT)
            ->where('status', 'ready')->where('is_active', true)
            ->with(['system:id,label', 'client:id,name,short_name'])
            ->when($request->filled('catalog_id'), fn ($q) => $q->where('catalog_id', $request->catalog_id))
            ->when($request->filled('audience'), fn ($q) => $q->where('audience', $request->audience))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->orderBy('title')->limit(500)->get();

        return response()->json($docs->map(fn ($d) => $this->articleCard($d)));
    }

    /** Temas (tópicos) derivados de los documentos: la lista granular tipo KB ITSM. */
    public function topics(Request $request): JsonResponse
    {
        $this->assertStaff($request);

        $docs = AiDocument::query()
            ->where('collection', AiDocument::COLLECTION_SUPPORT)
            ->where('status', 'ready')->where('is_active', true)
            ->with(['system:id,label'])
            ->when($request->filled('catalog_id'), fn ($q) => $q->where('catalog_id', $request->catalog_id))
            ->when($request->filled('audience'), fn ($q) => $q->where('audience', $request->audience))
            ->get();

        $topics = $docs->flatMap(fn ($d) => \App\Support\MarkdownTopics::fromDocument($d));

        if ($s = trim((string) $request->query('search', ''))) {
            $needle = mb_strtolower($s);
            $topics = $topics->filter(fn ($t) => str_contains(mb_strtolower($t['title'] . ' ' . $t['body']), $needle));
        }

        return response()->json($topics->values()->all());
    }

    /** Artículo completo (cuerpo legible). */
    public function article(Request $request, AiDocument $document): JsonResponse
    {
        $this->assertStaff($request);
        $this->assertReadableSupport($document);

        return response()->json($this->articleFull($document));
    }

    /** Búsqueda semántica (RAG) sobre los artículos de soporte. */
    public function articleSearch(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        return response()->json($this->semanticSearch(
            (string) $request->query('q', ''),
            [
                'catalog_id' => $request->filled('catalog_id') ? (int) $request->catalog_id : null,
            ],
        ));
    }

    /**
     * Búsqueda semántica reutilizable: devuelve ARTÍCULOS (documentos) con el
     * fragmento que mejor casó. Usada por el lector de staff y el portal.
     */
    public function semanticSearch(string $query, array $extraFilters = []): array
    {
        $query = trim($query);
        if ($query === '') return [];

        $rag = app(RagService::class);
        if (! $rag->isOperational()) return [];

        $filters = array_merge(['collection' => AiDocument::COLLECTION_SUPPORT], array_filter($extraFilters, fn ($v) => $v !== null));
        try {
            $hits = $rag->search($query, topK: 12, minScore: 0.12, filters: $filters);
        } catch (\Throwable) {
            return [];
        }

        // El fragmento trae el TÍTULO del documento (no su id); mapeamos por título.
        // Agrupamos por documento conservando el mejor snippet (los hits vienen ordenados).
        $snippets = collect($hits)->take(8);
        $docs = AiDocument::where('collection', AiDocument::COLLECTION_SUPPORT)
            ->where('status', 'ready')->where('is_active', true)
            ->when(! empty($extraFilters['client_ids']), fn ($q) => $q->where(fn ($w) =>
                $w->whereNull('client_id')->orWhereIn('client_id', $extraFilters['client_ids'])))
            ->with(['system:id,label', 'client:id,name,short_name'])
            ->get()->keyBy('title');

        $out = [];
        $seen = [];
        foreach ($snippets as $h) {
            $doc = $docs->get($h['document'] ?? '');
            if (! $doc || isset($seen[$doc->id])) continue;
            $seen[$doc->id] = true;
            $out[] = array_merge($this->articleCard($doc), [
                'snippet' => \Illuminate\Support\Str::limit(trim((string) $h['content']), 220),
                'score'   => round((float) $h['score'], 3),
            ]);
        }
        return $out;
    }

    // ── Serialización de artículos ────────────────────────────────────

    private function articleCard(AiDocument $d): array
    {
        return [
            'id'       => $d->id,
            'title'    => $d->title,
            'kind'     => $d->kind,
            'audience' => $d->audience,
            'system'   => $d->system ? ['id' => $d->system->id, 'label' => $d->system->label] : null,
            'client'   => $d->client ? ['id' => $d->client->id, 'name' => $d->client->short_name ?: $d->client->name] : null,
            'excerpt'  => $this->excerpt($d->body_md),
            'updated_at' => $d->updated_at,
        ];
    }

    private function articleFull(AiDocument $d): array
    {
        return array_merge($this->articleCard($d), [
            'body_md' => (string) $d->body_md,
        ]);
    }

    /** Resumen de texto plano del cuerpo markdown (para tarjetas). */
    private function excerpt(?string $md): string
    {
        $t = (string) $md;
        $t = preg_replace('/^#{1,6}\s+/m', '', $t);   // encabezados
        $t = preg_replace('/[*_`>#-]/', ' ', $t);      // símbolos markdown
        $t = trim(preg_replace('/\s+/', ' ', $t));
        return \Illuminate\Support\Str::limit($t, 180);
    }

    private function assertStaff(Request $request): void
    {
        // El lector navegable es para el equipo; los solicitantes usan el portal.
        abort_if($request->user()->hasRole('solicitante') && ! $request->user()->hasAnyRole(['superadmin', 'admin', 'admin-cliente', 'admin-sitio', 'ingeniero', 'tecnico']), 403);
    }

    private function assertReadableSupport(AiDocument $document): void
    {
        abort_unless(
            $document->collection === AiDocument::COLLECTION_SUPPORT
                && $document->status === 'ready' && $document->is_active,
            404,
        );
    }

    // ── Internos ─────────────────────────────────────────────────────

    private function assertSupport(AiDocument $document): void
    {
        abort_unless($document->collection === AiDocument::COLLECTION_SUPPORT, 404);
    }

    private function serialize(AiDocument $d): array
    {
        return [
            'id'                => $d->id,
            'title'             => $d->title,
            'kind'              => $d->kind,
            'audience'          => $d->audience,
            'system'            => $d->system ? ['id' => $d->system->id, 'label' => $d->system->label] : null,
            'client'            => $d->client ? ['id' => $d->client->id, 'name' => $d->client->short_name ?: $d->client->name] : null,
            'chunks_count'      => (int) $d->chunks_count,
            'status'            => $d->status,
            'error'             => $d->error,
            'structured'        => (bool) $d->structured,
            'is_active'         => (bool) $d->is_active,
            'original_filename' => $d->original_filename,
            'has_file'          => (bool) $d->file_path,
            'author'            => $d->author?->name,
            'updated_at'        => $d->updated_at,
        ];
    }
}
