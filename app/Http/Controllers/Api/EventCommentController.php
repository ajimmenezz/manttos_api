<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\Notification;
use App\Models\User;
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

        $comment = DB::transaction(function () use ($event, $author, $data, $parent, $mentionable) {
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

            $this->notify($event, $comment, $author, $mentionIds, $parent);

            return $comment;
        });

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
        // Solo roles que EXISTAN y NO estén archivados: el scope role() de Spatie usa
        // App\Models\Role (con SoftDeletes) y lanza RoleDoesNotExist si el rol no está o
        // fue archivado (p. ej. `admin`). El whereNull('deleted_at') lo excluye.
        $adminRoles = DB::table('roles')
            ->whereIn('name', ['superadmin', 'admin'])
            ->where('guard_name', 'web')
            ->whereNull('deleted_at')
            ->pluck('name')->all();

        $ids = collect()
            ->merge($adminRoles ? User::role($adminRoles)->pluck('id') : [])
            ->merge(DB::table('client_user')->where('client_id', $event->client_id)->pluck('user_id'))
            ->merge(DB::table('site_user')->where('site_id', $event->site_id)->pluck('user_id'))
            ->merge(DB::table('client_engineers')->where('client_id', $event->client_id)->pluck('user_id'))
            ->merge(DB::table('site_engineers')->where('site_id', $event->site_id)->pluck('user_id'))
            ->unique()->values();

        return User::whereIn('id', $ids)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'email']);
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

    /** Genera notificaciones in-app: a los mencionados y al autor del comentario padre. */
    private function notify(Event $event, EventComment $comment, User $author, $mentionIds, ?EventComment $parent): void
    {
        $payload = [
            'event_id'   => $event->id,
            'folio'      => $event->folio,
            'comment_id' => $comment->id,
            'actor_id'   => $author->id,
            'actor_name' => $author->name,
            'snippet'    => Str::limit(trim($this->plainBody($comment->body)), 140),
        ];

        foreach ($mentionIds as $uid) {
            Notification::createFor($uid, 'event_mention', $payload);
        }

        // Respuesta: avisa al autor del comentario padre (si no es el mismo ni ya fue mencionado).
        if ($parent && $parent->user_id !== $author->id && ! $mentionIds->contains($parent->user_id)) {
            Notification::createFor($parent->user_id, 'event_reply', $payload);
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
