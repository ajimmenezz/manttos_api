<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Base de conocimiento en el PORTAL (autoservicio del solicitante): solo artículos
 * de audiencia "Cliente" (support), publicados y dentro del alcance del solicitante
 * (globales + los de sus clientes). Deflexión estilo ITSM: buscar antes de abrir ticket.
 */
class PortalKnowledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $this->scopedQuery($request->user())
            ->when($request->filled('catalog_id'), fn ($x) => $x->where('catalog_id', $request->catalog_id))
            ->when($request->filled('search'), fn ($x) => $x->where('title', 'ilike', "%{$request->search}%"))
            ->with(['system:id,label'])
            ->orderBy('title')->limit(300)->get();

        return response()->json([
            'articles' => $q->map(fn ($d) => $this->card($d)),
            'systems'  => $q->pluck('system')->filter()->unique('id')->values()
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->label]),
        ]);
    }

    /** Temas (tópicos) navegables derivados de los artículos del alcance del solicitante. */
    public function topics(Request $request): JsonResponse
    {
        $docs = $this->scopedQuery($request->user())
            ->when($request->filled('catalog_id'), fn ($x) => $x->where('catalog_id', $request->catalog_id))
            ->with(['system:id,label'])->orderBy('title')->limit(300)->get();

        $topics = $docs->flatMap(fn ($d) => \App\Support\MarkdownTopics::fromDocument($d));

        if ($s = trim((string) $request->query('search', ''))) {
            $needle = mb_strtolower($s);
            $topics = $topics->filter(fn ($t) => str_contains(mb_strtolower($t['title'] . ' ' . $t['body']), $needle));
        }

        return response()->json([
            'topics'  => $topics->values()->all(),
            'systems' => $docs->pluck('system')->filter()->unique('id')->values()
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->label]),
        ]);
    }

    public function show(Request $request, AiDocument $document): JsonResponse
    {
        // Debe estar en el alcance del solicitante.
        abort_unless($this->scopedQuery($request->user())->whereKey($document->id)->exists(), 404);

        $document->load('system:id,label');
        return response()->json(array_merge($this->card($document), ['body_md' => (string) $document->body_md]));
    }

    /** Búsqueda semántica acotada al alcance del solicitante. */
    public function search(Request $request): JsonResponse
    {
        $clientIds = $this->clientIds($request->user());
        $results = app(KnowledgeController::class)->semanticSearch(
            (string) $request->query('q', ''),
            ['audience' => AiDocument::AUDIENCE_SUPPORT, 'client_ids' => $clientIds->all()],
        );
        // Filtra por seguridad a audiencia support (semanticSearch ya filtra collection).
        $results = array_values(array_filter($results, fn ($r) => ($r['audience'] ?? 'support') === AiDocument::AUDIENCE_SUPPORT));

        return response()->json($results);
    }

    // ── Internos ─────────────────────────────────────────────────────

    /** Consulta base: artículos support publicados en el alcance del solicitante. */
    private function scopedQuery(User $user)
    {
        $clientIds = $this->clientIds($user);
        return AiDocument::query()
            ->where('collection', AiDocument::COLLECTION_SUPPORT)
            ->where('audience', AiDocument::AUDIENCE_SUPPORT)
            ->where('status', 'ready')->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('client_id')->when($clientIds->isNotEmpty(),
                fn ($x) => $x->orWhereIn('client_id', $clientIds->all())));
    }

    /** Clientes a los que el solicitante tiene alcance (directos + por sus sitios). */
    private function clientIds(User $user)
    {
        return $user->solicitanteClients()->pluck('clients.id')
            ->merge(Site::whereIn('id', $user->solicitanteSites()->pluck('sites.id'))->pluck('client_id'))
            ->filter()->unique()->values();
    }

    private function card(AiDocument $d): array
    {
        $t = preg_replace('/^#{1,6}\s+/m', '', (string) $d->body_md);
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[*_`>#-]/', ' ', $t)));
        return [
            'id'      => $d->id,
            'title'   => $d->title,
            'system'  => $d->system ? ['id' => $d->system->id, 'label' => $d->system->label] : null,
            'excerpt' => Str::limit($t, 180),
            'updated_at' => $d->updated_at,
        ];
    }
}
