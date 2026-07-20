<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Autorización de canales privados del chat interno (sección 4.2 del spec).
 *
 * Es la ÚNICA barrera del tiempo real: por REST el alcance lo cuida ChatScope, pero
 * un WebSocket suscrito se queda escuchando, así que aquí se valida membresía ACTIVA
 * (left_at null) en cada suscripción.
 */

// Canal de la conversación: solo participantes activos ven los mensajes en vivo.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation?->hasActiveParticipant($user->id) ?? false;
});

// Canal personal: badges globales de no-leídos y sincronía entre dispositivos del mismo usuario.
Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
});

/*
 * Presencia: quién está conectado ahora mismo.
 *
 * Es un canal ÚNICO y global, no uno por conversación: lo que la interfaz necesita
 * es "¿esta persona está en línea?", y con un canal por conversación habría que
 * suscribirse a todas para saberlo.
 *
 * Devolver un arreglo (en vez de true) es lo que lo convierte en canal de presencia;
 * ese arreglo es lo ÚNICO que ven los demás miembros, así que aquí no va nada
 * sensible: solo id y nombre, que ya se ven en el directorio del chat.
 */
Broadcast::channel('chat-presence', function (User $user) {
    if (! $user->can('chat.use')) {
        return null;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
