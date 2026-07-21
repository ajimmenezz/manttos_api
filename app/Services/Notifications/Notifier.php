<?php

namespace App\Services\Notifications;

use App\Jobs\SendPush;
use App\Models\Notification;
use App\Models\NotificationPreference;

/**
 * Punto único para notificar a usuarios: escribe la bandeja in-app Y dispara el push,
 * de forma coherente. Antes cada camino iba por su lado (el chat empujaba push sin
 * bandeja; los eventos escribían bandeja sin push).
 *
 * Reglas que aplica siempre:
 *  - Nunca notifica al actor (quien provocó el evento no se avisa a sí mismo).
 *  - Respeta las preferencias del usuario: si apagó ese tipo, no le llega ni bandeja ni
 *    push (apagado = apagado del todo, es lo que el usuario espera al desactivarlo).
 *  - Deduplica destinatarios.
 *
 * El envío push va encolado (SendPush); la escritura de bandeja es inmediata para que el
 * badge/centro de notificaciones lo refleje al instante.
 */
class Notifier
{
    /**
     * @param  iterable<int>       $userIds  destinatarios candidatos (se filtran aquí)
     * @param  array<string,mixed> $data     payload de la bandeja (rich); del push se
     *                                        derivan solo los escalares + el type
     * @param  int|null            $actorId  quien originó la acción (se excluye)
     */
    public function send(iterable $userIds, string $type, array $data, string $title, string $body, ?int $actorId = null): void
    {
        $recipients = collect($userIds)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($actorId !== null) {
            $recipients = $recipients->reject(fn ($id) => $id === (int) $actorId);
        }
        if ($recipients->isEmpty()) {
            return;
        }

        // Preferencias: fuera los que apagaron este tipo (ausencia = encendido).
        $optedOut = NotificationPreference::optedOut($recipients->all(), $type);
        $targets = $recipients->reject(fn ($id) => $optedOut->contains($id))->values();
        if ($targets->isEmpty()) {
            return;
        }

        // Bandeja in-app (una fila por destinatario).
        foreach ($targets as $uid) {
            Notification::createFor($uid, $type, $data);
        }

        // Push: el payload FCM/APNs solo admite strings; se pasan los escalares + el type
        // (con eso a la app le basta para el deep-link).
        $pushData = ['type' => $type];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $pushData[$key] = (string) $value;
            }
        }

        SendPush::dispatch($targets->all(), $title, $body, $pushData);
    }
}
