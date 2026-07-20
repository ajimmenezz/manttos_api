<?php

namespace App\Services\Chat;

use App\Events\MessageSent;
use App\Jobs\SendChatPush;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Support\ChatScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del chat interno en un solo lugar: crear conversaciones respetando el
 * alcance multi-cliente, enviar mensajes (idempotentes) y llevar los no-leídos.
 *
 * Los controladores solo validan formato y traducen a HTTP; toda decisión de
 * alcance vive aquí o en ChatScope para que el móvil y la web no puedan divergir.
 */
class ChatService
{
    /**
     * Conversación 1-a-1 entre dos usuarios: la devuelve si ya existe, si no la crea.
     *
     * La carrera "ambos abren el chat a la vez" se resuelve con el índice único de
     * `direct_key`, no con un lock: si el insert choca, releemos la que ganó.
     */
    public function findOrCreateDirect(User $me, User $other): Conversation
    {
        if (! ChatScope::canContact($me, $other)) {
            throw ValidationException::withMessages([
                'user_id' => 'No puedes iniciar una conversación con este usuario.',
            ]);
        }

        $key = Conversation::directKey($me->id, $other->id);

        if ($existing = Conversation::where('direct_key', $key)->first()) {
            // Si alguno se había salido, lo reactivamos en vez de duplicar el hilo.
            $this->ensureActiveParticipants($existing, collect([$me, $other]));
            return $existing;
        }

        $clientId = ChatScope::resolveConversationClient(collect([$me, $other]));
        if ($clientId === false) {
            throw ValidationException::withMessages([
                'user_id' => 'No puedes iniciar una conversación con este usuario.',
            ]);
        }

        try {
            return DB::transaction(function () use ($me, $other, $key, $clientId) {
                $conversation = Conversation::create([
                    'type'       => 'direct',
                    'created_by' => $me->id,
                    'client_id'  => $clientId,
                    'direct_key' => $key,
                ]);
                $this->ensureActiveParticipants($conversation, collect([$me, $other]));

                return $conversation;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $_) {
            // La ganó el otro lado entre el SELECT y el INSERT: usamos la suya.
            return Conversation::where('direct_key', $key)->firstOrFail();
        }
    }

    /**
     * Grupo nuevo. El creador queda como admin; los miembros deben ser todos
     * contactables por él y no pueden mezclar clientes distintos.
     *
     * @param  array<int,int>  $userIds
     */
    public function createGroup(User $me, string $name, array $userIds): Conversation
    {
        $members = User::whereIn('id', $userIds)->where('is_active', true)->get();

        if ($members->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Selecciona al menos un participante.',
            ]);
        }

        foreach ($members as $member) {
            if ($member->id !== $me->id && ! ChatScope::canContact($me, $member)) {
                throw ValidationException::withMessages([
                    'user_ids' => "No puedes agregar a {$member->name} a un grupo.",
                ]);
            }
        }

        $all      = $members->reject(fn (User $u) => $u->id === $me->id)->push($me);
        $clientId = ChatScope::resolveConversationClient($all);

        if ($clientId === false) {
            throw ValidationException::withMessages([
                'user_ids' => 'No puedes mezclar usuarios de clientes distintos en un mismo grupo.',
            ]);
        }

        return DB::transaction(function () use ($me, $name, $all, $clientId) {
            $conversation = Conversation::create([
                'type'       => 'group',
                'name'       => $name,
                'created_by' => $me->id,
                'client_id'  => $clientId,
            ]);

            $this->ensureActiveParticipants($conversation, $all, adminIds: [$me->id]);

            return $conversation;
        });
    }

    /**
     * Agrega participantes a un grupo. Valida contra el CLIENTE de la conversación,
     * no solo contra quien invita: así un interno no puede meter a un cliente ajeno
     * en un grupo que ya está amarrado a otro cliente.
     *
     * @param  array<int,int>  $userIds
     * @return EloquentCollection<int,User>  los realmente agregados
     */
    public function addParticipants(Conversation $conversation, User $me, array $userIds): EloquentCollection
    {
        abort_unless($conversation->isGroup(), 422, 'Solo los grupos admiten participantes.');

        $users = User::whereIn('id', $userIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            if (! ChatScope::canContact($me, $user)) {
                throw ValidationException::withMessages([
                    'user_ids' => "No puedes agregar a {$user->name} a este grupo.",
                ]);
            }

            // Un externo solo entra si pertenece al cliente de la conversación.
            if (! ChatScope::isInternal($user)) {
                $shares = $conversation->client_id
                    && ChatScope::clientIds($user)->contains($conversation->client_id);

                if (! $shares) {
                    throw ValidationException::withMessages([
                        'user_ids' => "{$user->name} pertenece a otro cliente y no puede entrar a este grupo.",
                    ]);
                }
            }
        }

        // Si el grupo era interno (client_id null) y entra un externo, hereda su cliente.
        if ($conversation->client_id === null) {
            $clientId = ChatScope::resolveConversationClient(
                $conversation->activeUsers()->get()->merge($users)
            );
            if ($clientId === false) {
                throw ValidationException::withMessages([
                    'user_ids' => 'No puedes mezclar usuarios de clientes distintos en un mismo grupo.',
                ]);
            }
            if ($clientId !== null) {
                $conversation->update(['client_id' => $clientId]);
            }
        }

        $this->ensureActiveParticipants($conversation, $users);

        return $users;
    }

    /**
     * Alta (o reactivación) de participantes. Idempotente: `updateOrCreate` sobre el
     * único (conversation_id, user_id) revive a quien se había salido con left_at null.
     *
     * @param  Collection<int,User>  $users
     * @param  array<int,int>        $adminIds
     */
    public function ensureActiveParticipants(Conversation $conversation, Collection $users, array $adminIds = []): void
    {
        foreach ($users as $user) {
            $existing = ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->first();

            ConversationParticipant::updateOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $user->id],
                [
                    'left_at'   => null,
                    // No degradamos a admin existente al re-agregarlo.
                    'role'      => in_array($user->id, $adminIds, true) ? 'admin' : ($existing->role ?? 'member'),
                    'joined_at' => $existing?->joined_at ?? now(),
                ]
            );
        }
    }

    /**
     * Envía un mensaje y lo emite por WebSocket.
     *
     * `client_uuid` hace el envío idempotente para la cola offline del móvil: si el
     * reintento llega con el mismo uuid devolvemos el mensaje ya creado sin duplicar
     * ni re-emitir.
     *
     * @param  array<int,array<string,mixed>>  $attachments
     */
    public function sendMessage(
        Conversation $conversation,
        User $sender,
        ?string $body,
        array $attachments = [],
        ?int $replyToId = null,
        ?string $clientUuid = null,
    ): Message {
        abort_unless($conversation->hasActiveParticipant($sender->id), 403,
            'No participas en esta conversación.');

        if (($body === null || trim($body) === '') && empty($attachments)) {
            throw ValidationException::withMessages([
                'body' => 'El mensaje no puede estar vacío.',
            ]);
        }

        if ($clientUuid && $existing = Message::where('client_uuid', $clientUuid)->first()) {
            return $existing->load(['sender', 'attachments', 'replyTo']);
        }

        // Solo se responde a un mensaje del mismo hilo (si no, se ignora la liga).
        if ($replyToId) {
            $valid = Message::where('id', $replyToId)
                ->where('conversation_id', $conversation->id)
                ->exists();
            $replyToId = $valid ? $replyToId : null;
        }

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachments, $replyToId, $clientUuid) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $sender->id,
                'body'            => $body !== null ? trim($body) : null,
                'reply_to_id'     => $replyToId,
                'client_uuid'     => $clientUuid,
            ]);

            foreach ($attachments as $a) {
                $message->attachments()->create([
                    'url'       => $a['url'],
                    'mime'      => $a['mime']      ?? null,
                    'size'      => $a['size']      ?? null,
                    'kind'      => $a['kind']      ?? 'image',
                    'width'     => $a['width']     ?? null,
                    'height'    => $a['height']    ?? null,
                    'thumb_url' => $a['thumb_url'] ?? null,
                ]);
            }

            $conversation->update(['last_message_at' => $message->created_at]);

            // El emisor no se debe a sí mismo un no-leído: avanza su marca de agua.
            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_message_id' => $message->id]);

            return $message;
        });

        $message->load(['sender', 'attachments', 'replyTo']);

        broadcast(new MessageSent($message, $this->recipientIds($conversation, $sender->id)))->toOthers();

        // Push a quien no lo esté viendo. Va con retraso a propósito: le da tiempo al
        // acuse de lectura del WebSocket, y así no se notifica lo que ya se leyó.
        SendChatPush::dispatch($message->id)
            ->delay(now()->addSeconds(SendChatPush::READ_GRACE_SECONDS));

        return $message;
    }

    /** Participantes activos menos el emisor (destinatarios del broadcast y, en fase 2, del push). */
    public function recipientIds(Conversation $conversation, int $exceptUserId): array
    {
        return $conversation->participants()
            ->whereNull('left_at')
            ->where('user_id', '!=', $exceptUserId)
            ->pluck('user_id')
            ->all();
    }

    /**
     * Avanza la marca de agua de lectura. Nunca retrocede: leer un mensaje viejo no
     * debe resucitar no-leídos que ya se habían bajado en otro dispositivo.
     */
    public function markRead(Conversation $conversation, User $user, ?int $messageId = null): int
    {
        $participant = $conversation->participantFor($user->id);
        abort_if($participant === null, 403, 'No participas en esta conversación.');

        $target = $messageId
            ?? $conversation->messages()->max('id')
            ?? 0;

        $last = max((int) $participant->last_read_message_id, (int) $target);

        if ($last !== (int) $participant->last_read_message_id) {
            $participant->update(['last_read_message_id' => $last]);
        }

        return $last;
    }

    /** No-leídos de una conversación para un usuario (por marca de agua, sin tocar message_reads). */
    public function unreadCount(Conversation $conversation, int $userId, ?ConversationParticipant $participant = null): int
    {
        $participant ??= $conversation->participantFor($userId);
        if (! $participant) {
            return 0;
        }

        return $conversation->messages()
            ->where('id', '>', (int) $participant->last_read_message_id)
            ->where('sender_id', '!=', $userId)
            ->count();
    }
}
