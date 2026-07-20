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
