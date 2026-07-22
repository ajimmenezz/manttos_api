<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\EventAudience;
use App\Support\NotificationType;
use App\Support\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversación de un evento: comentarios en hilos anidados (parent_id) con
 * @menciones (formato del cuerpo: `@[Nombre](userId)`) y notificaciones in-app.
 */
class EventCommentController extends Controller
{
    use ScopesEvents;

    private function authorizeAccess(Request $request, Event $event): void
    {
        $ok = $this->scopeEvents($request, Event::query()->where('events.id', $event->id))->exists();
        abort_unless($ok, 403, 'No tienes acceso a este evento.');
    }

    // ─── Listado (plano; el front arma el árbol por parent_id) ─────
    public function index(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('events.view'), 403, 'No autorizado para esta acción.');
        $this->authorizeAccess($request, $event);

        // withTrashed: un comentario borrado se conserva ("comentario eliminado")
        // para no romper la estructura del hilo si tenía respuestas.
        $comments = EventComment::withTrashed()
            ->where('event_id', $event->id)
            ->with(['user:id,name', 'mentionedUsers:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => $this->serialize($c, $request));

        return response()->json($comments);
    }

    // ─── Crear comentario o respuesta ─────────────────────────────
    public function store(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('events.comment'), 403, 'No autorizado para esta acción.');
        $this->authorizeAccess($request, $event);

        $data = $request->validate([
            'body'      => 'required|string|max:5000',
            'parent_id' => 'nullable|integer',
        ]);

        // El padre (si hay) debe pertenecer al mismo evento.
        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = EventComment::where('id', $data['parent_id'])->where('event_id', $event->id)->first();
            abort_unless($parent, 422, 'El comentario al que respondes no existe en este evento.');
        }

        $author = $request->user();
        $mentionable = $this->mentionableUsers($event)->keyBy('id');

        [$comment, $mentionIds] = DB::transaction(function () use ($event, $author, $data, $parent, $mentionable) {
            $comment = EventComment::create([
                'event_id'  => $event->id,
                'user_id'   => $author->id,
                'parent_id' => $parent?->id,
                'body'      => $data['body'],
            ]);

            // Menciones válidas (dentro del conjunto arrobable, sin el propio autor).
            $mentionIds = collect($this->extractMentionIds($data['body']))
                ->filter(fn ($id) => $mentionable->has($id) && $id !== $author->id)
                ->values();
            if ($mentionIds->isNotEmpty()) {
                $comment->mentionedUsers()->sync($mentionIds->all());
            }

            return [$comment, $mentionIds];
        });

        // Fuera de la transacción: notificar (bandeja + push) no debe correr con datos
        // sin confirmar ni encolar el job antes del commit.
        $this->notify($event, $comment, $author, $mentionIds, $parent);

        // Webhooks salientes. `comment_added` sale por cualquier comentario; además, si
        // el comentario responde a otro o menciona usuarios, se disparan también los
        // escenarios específicos (cada uno es suscribible por separado).
        $dispatcher = app(WebhookDispatcher::class);
        $commentPayload = [
            'id'         => $comment->id,
            'body'       => $this->plainBody($comment->body),
            'author'     => ['id' => $author->id, 'name' => $author->name],
            'created_at' => $comment->created_at?->toISOString(),
        ];

        $dispatcher->dispatch(
            WebhookEvent::EVENT_COMMENT_ADDED,
            $event->client_id,
            $event->site_id,
            WebhookEvent::eventData($event, $author, ['comment' => $commentPayload]),
        );

        if ($parent) {
            $dispatcher->dispatch(
                WebhookEvent::EVENT_REPLY,
                $event->client_id,
                $event->site_id,
                WebhookEvent::eventData($event, $author, [
                    'comment' => $commentPayload,
                    'parent'  => [
                        'id'      => $parent->id,
                        'author'  => $parent->user_id ? ['id' => $parent->user_id] : null,
                    ],
                ]),
            );
        }

        if ($mentionIds->isNotEmpty()) {
            $mentioned = $mentionable->only($mentionIds->all())
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();
            $dispatcher->dispatch(
                WebhookEvent::EVENT_MENTION,
                $event->client_id,
                $event->site_id,
                WebhookEvent::eventData($event, $author, [
                    'comment'          => $commentPayload,
                    'mentioned_users'  => $mentioned,
                ]),
            );
        }

        $comment->load(['user:id,name', 'mentionedUsers:id,name']);
        return response()->json(['message' => 'Comentario agregado.', 'comment' => $this->serialize($comment, $request)], 201);
    }

    // ─── Editar (solo el autor) ───────────────────────────────────
    public function update(Request $request, Event $event, EventComment $comment): JsonResponse
    {
        abort_unless($request->user()->can('events.comment'), 403, 'No autorizado para esta acción.');
        $this->authorizeAccess($request, $event);
        abort_unless($comment->event_id === $event->id, 404);
        abort_unless($comment->user_id === $request->user()->id, 403, 'Solo el autor puede editar su comentario.');

        $data = $request->validate(['body' => 'required|string|max:5000']);

        $mentionable = $this->mentionableUsers($event)->keyBy('id');
        $mentionIds = collect($this->extractMentionIds($data['body']))
            ->filter(fn ($id) => $mentionable->has($id) && $id !== $comment->user_id)
            ->values();

        DB::transaction(function () use ($comment, $data, $mentionIds) {
            $comment->update(['body' => $data['body']]);
            $comment->mentionedUsers()->sync($mentionIds->all()); // re-sincroniza (sin re-notificar en edición)
        });

        $comment->load(['user:id,name', 'mentionedUsers:id,name']);
        return response()->json(['message' => 'Comentario actualizado.', 'comment' => $this->serialize($comment, $request)]);
    }

    // ─── Eliminar (autor o admin) — borrado lógico ────────────────
    public function destroy(Request $request, Event $event, EventComment $comment): JsonResponse
    {
        abort_unless($request->user()->can('events.comment'), 403, 'No autorizado para esta acción.');
        $this->authorizeAccess($request, $event);
        abort_unless($comment->event_id === $event->id, 404);

        $user = $request->user();
        $canDelete = $comment->user_id === $user->id || $user->hasAnyRole(['superadmin', 'admin']);
        abort_unless($canDelete, 403, 'No puedes eliminar este comentario.');

        $comment->delete(); // soft delete: conserva el hilo
        return response()->json(['message' => 'Comentario eliminado.']);
    }

    // ─── Usuarios arrobables (con acceso al evento) ───────────────
    public function mentionable(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('events.comment'), 403, 'No autorizado para esta acción.');
        $this->authorizeAccess($request, $event);
        return response()->json($this->mentionableUsers($event));
    }

    // ── Helpers ───────────────────────────────────────────────────

    /** Usuarios activos que pueden ver/atender el evento (candidatos a @mención). */
    private function mentionableUsers(Event $event)
    {
        // Mismo conjunto que "a quién le importa el evento": centralizado en EventAudience.
        return EventAudience::interestedUsers($event);
    }

    /** IDs de usuario referenciados en el cuerpo con el formato `@[Nombre](123)`. */
    private function extractMentionIds(string $body): array
    {
        preg_match_all('/@\[[^\]]+\]\((\d+)\)/', $body, $m);
        return array_values(array_unique(array_map('intval', $m[1] ?? [])));
    }

    /** Texto plano para el snippet de la notificación (quita los tokens de mención). */
    private function plainBody(string $body): string
    {
        return preg_replace('/@\[([^\]]+)\]\(\d+\)/', '@$1', $body);
    }

    /**
     * Notifica (bandeja + push) por un comentario nuevo, sin duplicar avisos a la misma
     * persona. Prioridad: mención > respuesta a tu comentario > comentario en tu evento.
     */
    private function notify(Event $event, EventComment $comment, User $author, $mentionIds, ?EventComment $parent): void
    {
        $snippet = Str::limit(trim($this->plainBody($comment->body)), 140);
        $payload = [
            'event_id'   => $event->id,
            'folio'      => $event->folio,
            'comment_id' => $comment->id,
            'actor_id'   => $author->id,
            'actor_name' => $author->name,
            'snippet'    => $snippet,
        ];

        $notifier = app(Notifier::class);
        $notified = collect();       // a quién ya se avisó (para no repetir por otra vía)

        // 1) Menciones (@): al mencionado.
        if ($mentionIds->isNotEmpty()) {
            $notifier->send($mentionIds->all(), NotificationType::EVENT_MENTION, $payload,
                "Te mencionaron en {$event->folio}", "{$author->name}: {$snippet}", $author->id);
            $notified = $notified->merge($mentionIds->all());
        }

        // 2) Respuesta: al autor del comentario padre (si no fue ya mencionado).
        if ($parent && $parent->user_id !== $author->id && ! $notified->contains($parent->user_id)) {
            $notifier->send([$parent->user_id], NotificationType::EVENT_REPLY, $payload,
                "Respondieron tu comentario en {$event->folio}", "{$author->name}: {$snippet}", $author->id);
            $notified->push($parent->user_id);
        }

        // 3) Comentario en tu evento: al creador del evento (si no se avisó ya por 1/2).
        if ($event->created_by && $event->created_by !== $author->id && ! $notified->contains($event->created_by)) {
            $notifier->send([$event->created_by], NotificationType::EVENT_COMMENT, $payload,
                "Nuevo comentario en {$event->folio}", "{$author->name}: {$snippet}", $author->id);
        }
    }

    private function serialize(EventComment $c, Request $request): array
    {
        $trashed = $c->trashed();
        return [
            'id'                 => $c->id,
            'parent_id'          => $c->parent_id,
            'body'               => $trashed ? null : $c->body,
            'deleted'            => $trashed,
            'user'               => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
            'mentioned_users'    => $trashed ? [] : $c->mentionedUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'can_edit'           => ! $trashed && $c->user_id === $request->user()->id,
            'can_delete'         => ! $trashed && ($c->user_id === $request->user()->id || $request->user()->hasAnyRole(['superadmin', 'admin'])),
            'edited'             => ! $trashed && $c->updated_at && $c->created_at && $c->updated_at->gt($c->created_at),
            'created_at'         => $c->created_at,
            'updated_at'         => $c->updated_at,
        ];
    }
}
