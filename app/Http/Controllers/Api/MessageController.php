<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mensajes del chat interno: hilo paginado hacia atrás, envío y borrado.
 *
 * El paginado es por CURSOR de id (no offset): en un hilo vivo los mensajes nuevos
 * corren el offset y se repetirían o perderían filas al hacer scroll.
 */
class MessageController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    /**
     * Hilo, del más nuevo al más viejo. `before` = id del mensaje más antiguo que ya
     * tiene el cliente. Responde en orden ascendente (listo para pintar).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        $data = $request->validate([
            'before'   => ['sometimes', 'nullable', 'integer'],
            'after'    => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $data['per_page'] ?? 40;

        $query = $conversation->messages()
            ->withTrashed()                       // el hueco "mensaje eliminado" se sigue viendo
            ->with(['sender:id,name', 'attachments', 'replyTo:id,body,sender_id']);

        if (! empty($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        // `after` sirve al fallback de polling cuando el WebSocket está caído.
        if (! empty($data['after'])) {
            $query->where('id', '>', $data['after']);
        }

        $messages = $query->orderByDesc('id')->limit($perPage + 1)->get();

        $hasMore  = $messages->count() > $perPage;
        $messages = $messages->take($perPage)->reverse()->values();

        return response()->json([
            'data'     => $messages->map(fn (Message $m) => $this->serialize($m)),
            'has_more' => $hasMore,
        ]);
    }

    /** Envía un mensaje (texto y/o adjuntos ya subidos por POST /media/upload). */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation, strict: true);

        $data = $request->validate([
            'body'                   => ['nullable', 'string', 'max:5000'],
            'reply_to_id'            => ['nullable', 'integer'],
            'client_uuid'            => ['nullable', 'string', 'max:64'],
            'attachments'            => ['nullable', 'array', 'max:20'],
            'attachments.*.url'      => ['required', 'string', 'max:1000'],
            'attachments.*.mime'     => ['nullable', 'string', 'max:120'],
            'attachments.*.size'     => ['nullable', 'integer'],
            'attachments.*.kind'     => ['nullable', 'in:image,file,video'],
            'attachments.*.width'    => ['nullable', 'integer'],
            'attachments.*.height'   => ['nullable', 'integer'],
            'attachments.*.thumb_url'=> ['nullable', 'string', 'max:1000'],
        ]);

        $message = $this->chat->sendMessage(
            $conversation,
            $user,
            $data['body'] ?? null,
            $data['attachments'] ?? [],
            $data['reply_to_id'] ?? null,
            $data['client_uuid'] ?? null,
        );

        return response()->json(['data' => $this->serialize($message)], 201);
    }

    /**
     * Borra un mensaje propio (soft delete). Un admin del grupo también puede borrar
     * los ajenos: es el moderador del hilo.
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $user         = $request->user();
        $conversation = $message->conversation;

        abort_if($conversation === null, 404, 'La conversación ya no existe.');

        $participant = $conversation->participantFor($user->id);
        abort_if($participant === null, 403, 'No participas en esta conversación.');

        $canDelete = $message->sender_id === $user->id
            || ($conversation->isGroup() && $participant->isAdmin())
            || $user->can('chat.all-conversations');

        abort_unless($canDelete, 403, 'Solo puedes eliminar tus propios mensajes.');

        $message->delete();

        broadcast(new MessageDeleted($conversation->id, $message->id, $user->id))->toOthers();

        return response()->json(['message' => 'Mensaje eliminado.']);
    }

    /**
     * @param  bool  $strict  si true, `chat.all-conversations` NO alcanza: auditar es
     *                        leer, no escribir en un hilo del que no formas parte.
     */
    private function authorizeParticipant(Request $request, Conversation $conversation, bool $strict = false): User
    {
        $user = $request->user();

        if ($conversation->hasActiveParticipant($user->id)) {
            return $user;
        }

        abort_unless(! $strict && $user->can('chat.all-conversations'), 403,
            'No participas en esta conversación.');

        return $user;
    }

    private function serialize(Message $message): array
    {
        $deleted = $message->trashed();

        return [
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id'       => $message->sender_id,
            'sender_name'     => $message->sender?->name,
            'body'            => $deleted ? null : $message->body,
            'deleted'         => $deleted,
            'edited_at'       => $message->edited_at?->toISOString(),
            'client_uuid'     => $message->client_uuid,
            'created_at'      => $message->created_at?->toISOString(),
            'reply_to'        => $message->replyTo ? [
                'id'        => $message->replyTo->id,
                'body'      => $message->replyTo->body,
                'sender_id' => $message->replyTo->sender_id,
            ] : null,
            'attachments' => $deleted ? [] : $message->attachments->map(fn ($a) => [
                'id'        => $a->id,
                'url'       => $a->url,
                'kind'      => $a->kind,
                'mime'      => $a->mime,
                'size'      => $a->size,
                'width'     => $a->width,
                'height'    => $a->height,
                'thumb_url' => $a->thumb_url,
            ])->values(),
        ];
    }
}
