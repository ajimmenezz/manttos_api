<?php

namespace App\Support;

use App\Models\Event;
use App\Models\User;

/**
 * Catálogo de eventos de negocio que se pueden entregar por webhook saliente, y el
 * armado del payload que se envía. Fuente de verdad única: la UI de suscripción y la
 * documentación leen de aquí.
 *
 * El sobre (envelope) es estable: { event, occurred_at, data }. `data` trae el recurso
 * afectado ya serializado, para que el tercero no tenga que re-consultar nuestra API.
 */
class WebhookEvent
{
    public const EVENT_CREATED = 'event.created';
    public const EVENT_STATUS_CHANGED = 'event.status_changed';
    public const EVENT_ASSIGNED = 'event.assigned';
    public const EVENT_COMMENT_ADDED = 'event.comment_added';

    /**
     * Escenarios ofrecidos al suscribir un webhook.
     *
     * @return array<int,array{type:string,group:string,label:string,description:string}>
     */
    public static function catalog(): array
    {
        return [
            ['type' => self::EVENT_CREATED,        'group' => 'Eventos', 'label' => 'Evento creado',        'description' => 'Cuando se crea un evento en el cliente/sitio suscrito.'],
            ['type' => self::EVENT_STATUS_CHANGED, 'group' => 'Eventos', 'label' => 'Cambio de estado',      'description' => 'Cuando un evento cambia de estado (incluye estado anterior y nuevo).'],
            ['type' => self::EVENT_ASSIGNED,       'group' => 'Eventos', 'label' => 'Evento asignado',       'description' => 'Cuando un evento se asigna (o reasigna) a un ingeniero.'],
            ['type' => self::EVENT_COMMENT_ADDED,  'group' => 'Eventos', 'label' => 'Comentario agregado',   'description' => 'Cuando se agrega un comentario a un evento.'],
        ];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_column(self::catalog(), 'type');
    }

    /**
     * Serializa un evento para el payload del webhook. `extra` agrega campos propios del
     * escenario (p. ej. estados de un cambio, o el comentario).
     *
     * @return array<string,mixed>
     */
    public static function eventData(Event $event, ?User $actor = null, array $extra = []): array
    {
        $event->loadMissing(['status:id,key,label', 'creator:id,name', 'assignee:id,name']);

        return array_merge([
            'id'          => $event->id,
            'folio'       => $event->folio,
            'client_id'   => $event->client_id,
            'site_id'     => $event->site_id,
            'system_id'   => $event->system_id,
            'device_id'   => $event->device_id,
            'priority'    => $event->priority,
            'status'      => $event->status ? ['key' => $event->status->key, 'label' => $event->status->label] : null,
            'description' => $event->description,
            'created_by'  => $event->creator ? ['id' => $event->creator->id, 'name' => $event->creator->name] : null,
            'assigned_to' => $event->assignee ? ['id' => $event->assignee->id, 'name' => $event->assignee->name] : null,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'created_at'  => $event->created_at?->toISOString(),
            'actor'       => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
        ], $extra);
    }
}
