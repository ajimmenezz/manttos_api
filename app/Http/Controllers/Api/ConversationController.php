<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageRead as MessageReadEvent;
use App\Events\TypingStarted;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Support\ChatScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat interno: conversaciones, participantes y directorio de contactos.
 * Los mensajes van en MessageController.
 *
 * Toda ruta exige el permiso `chat.use` (ver seeders); el alcance por cliente lo
 * resuelve ChatScope y la pertenencia a cada hilo se valida en cada acción.
 */
class ConversationController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    /** Lista de conversaciones del usuario, con último mensaje y no-leídos. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $participations = ConversationParticipant::with([
            'conversation.activeUsers:id,name,email',
            'conversation.messages' => fn ($q) => $q->latest('id')->limit(1)->with('sender:id,name'),
        ])
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->get()
            ->filter(fn ($p) => $p->conversation !== null)
            // Orden por actividad; las recién creadas (sin mensajes) primero por id.
            ->sortByDesc(fn ($p) => $p->conversation->last_message_at?->timestamp ?? PHP_INT_MAX)
            ->values();

        $data = $participations->map(fn (ConversationParticipant $p) => $this->serialize(
            $p->conversation, $user, $p, withParticipants: false
        ));

        return response()->json(['data' => $data]);
    }

    /**
     * Crea una conversación. `type=direct` requiere `user_id`; `type=group`,
     * `name` + `user_ids[]`. En directo devuelve la existente si ya la había (200).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'       => ['required', 'in:direct,group'],
            'user_id'    => ['required_if:type,direct', 'integer', 'exists:users,id'],
            'name'       => ['required_if:type,group', 'string', 'max:150'],
            'user_ids'   => ['required_if:type,group', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $user = $request->user();

        if ($data['type'] === 'direct') {
            $other   = User::findOrFail($data['user_id']);
            $existed = Conversation::where('direct_key', Conversation::directKey($user->id, $other->id))->exists();
            $conversation = $this->chat->findOrCreateDirect($user, $other);

            return response()->json(
                ['data' => $this->serialize($conversation->fresh(), $user)],
                $existed ? 200 : 201
            );
        }

        abort_unless($user->can('chat.group-manage'), 403, 'No tienes permiso para crear grupos.');

        $conversation = $this->chat->createGroup($user, $data['name'], $data['user_ids']);

        return response()->json(['data' => $this->serialize($conversation->fresh(), $user)], 201);
    }

    /** Detalle + participantes. */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        return response()->json(['data' => $this->serialize($conversation, $user)]);
    }

    /** Renombrar grupo / cambiar avatar (solo admin del grupo). */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        abort_unless($conversation->isGroup(), 422, 'Solo los grupos se pueden editar.');
        $this->authorizeGroupAdmin($conversation, $user);

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:150'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $conversation->update($data);

        return response()->json(['data' => $this->serialize($conversation->fresh(), $user)]);
    }

    /** Agrega participantes a un grupo (solo admin). */
    public function addParticipants(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);
        $this->authorizeGroupAdmin($conversation, $user);

        $data = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $added = $this->chat->addParticipants($conversation, $user, $data['user_ids']);

        return response()->json([
            'message' => $added->count() === 1
                ? "{$added->first()->name} se agregó al grupo."
                : "{$added->count()} participantes agregados.",
            'data'    => $this->serialize($conversation->fresh(), $user),
        ]);
    }

    /**
     * Quita a un participante o salida propia. No borra el histórico: marca `left_at`
     * para que sus mensajes anteriores sigan teniendo autor en el hilo.
     */
    public function removeParticipant(Request $request, Conversation $conversation, int $userId): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        abort_unless($conversation->isGroup(), 422, 'Solo los grupos admiten participantes.');

        $isSelf = $user->id === $userId;
        if (! $isSelf) {
            $this->authorizeGroupAdmin($conversation, $user);
        }

        $target = $conversation->participantFor($userId);
        abort_if($target === null, 404, 'El usuario no participa en esta conversación.');

        $target->update(['left_at' => now()]);

        // Grupo sin administradores activos: promovemos al participante más antiguo
        // para que nadie quede sin poder administrarlo.
        $hasAdmin = $conversation->participants()
            ->whereNull('left_at')->where('role', 'admin')->exists();

        if (! $hasAdmin) {
            $conversation->participants()
                ->whereNull('left_at')->oldest('joined_at')->first()
                ?->update(['role' => 'admin']);
        }

        return response()->json([
            'message' => $isSelf ? 'Saliste del grupo.' : 'Participante removido.',
        ]);
    }

    /** Marca leído hasta `message_id` (o hasta el último mensaje si no se envía). */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        $data = $request->validate([
            'message_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $last = $this->chat->markRead($conversation, $user, $data['message_id'] ?? null);

        broadcast(new MessageReadEvent($conversation->id, $user->id, $last))->toOthers();

        return response()->json(['last_read_message_id' => $last]);
    }

    /** "Está escribiendo…": solo broadcast, no se guarda nada. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        broadcast(new TypingStarted($conversation->id, $user->id, $user->name))->toOthers();

        return response()->json(['ok' => true]);
    }

    /**
     * Directorio de usuarios con los que YO puedo conversar (ya filtrado por alcance).
     * Es la fuente del buscador al iniciar un chat o armar un grupo.
     */
    public function contacts(Request $request): JsonResponse
    {
        $user   = $request->user();
        $search = trim((string) $request->query('search', ''));

        $query = ChatScope::contactableQuery($user)
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name');

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->where('users.name', 'ilike', "%{$search}%")
                  ->orWhere('users.email', 'ilike', "%{$search}%");
            });
        }

        return response()->json(['data' => $query->limit(50)->get()]);
    }

    /** Total de no-leídos (badge global del header / campana). */
    public function unreadTotal(Request $request): JsonResponse
    {
        $user = $request->user();

        $total = ConversationParticipant::where('user_id', $user->id)
            ->whereNull('left_at')
            ->get()
            ->sum(function (ConversationParticipant $p) use ($user) {
                $conversation = $p->conversation;
                return $conversation ? $this->chat->unreadCount($conversation, $user->id, $p) : 0;
            });

        return response()->json(['count' => $total]);
    }

    /** Participante activo, o 403/404. Devuelve el usuario autenticado por comodidad. */
    private function authorizeParticipant(Request $request, Conversation $conversation): User
    {
        $user = $request->user();

        if (! $conversation->hasActiveParticipant($user->id) && ! $user->can('chat.all-conversations')) {
            abort(403, 'No participas en esta conversación.');
        }

        return $user;
    }

    private function authorizeGroupAdmin(Conversation $conversation, User $user): void
    {
        if ($user->can('chat.all-conversations')) {
            return;
        }

        $participant = $conversation->participantFor($user->id);
        abort_unless($participant?->isAdmin(), 403, 'Solo un administrador del grupo puede hacer esto.');
    }

    /** Forma pública de una conversación (la misma para web y móvil). */
    private function serialize(
        Conversation $conversation,
        User $user,
        ?ConversationParticipant $participant = null,
        bool $withParticipants = true,
    ): array {
        $participant ??= $conversation->participantFor($user->id);
        $others       = $conversation->activeUsers()->where('users.id', '!=', $user->id)->get();
        $last         = $conversation->messages()->with('sender:id,name')->latest('id')->first();

        return [
            'id'   => $conversation->id,
            'type' => $conversation->type,
            // En un directo el nombre lo pone el otro participante, no la fila.
            'name' => $conversation->isGroup()
                ? $conversation->name
                : ($others->first()->name ?? 'Conversación'),
            'avatar_url'      => $conversation->avatar_url,
            'client_id'       => $conversation->client_id,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'unread_count'    => $this->chat->unreadCount($conversation, $user->id, $participant),
            'my_role'         => $participant?->role,
            'last_message'    => $last ? [
                'id'          => $last->id,
                'body'        => $last->trashed() ? null : $last->body,
                'deleted'     => $last->trashed(),
                'sender_id'   => $last->sender_id,
                'sender_name' => $last->sender?->name,
                'created_at'  => $last->created_at?->toISOString(),
            ] : null,
            'participants' => $withParticipants
                ? $conversation->activeUsers()->get()->map(fn (User $u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                    'role'  => $u->pivot->role,
                ])->values()
                : $others->take(3)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ];
    }
}
