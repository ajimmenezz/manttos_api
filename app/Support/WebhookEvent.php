<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\User;

/**
 * Catálogo de eventos de negocio que se pueden entregar por webhook saliente, y el
 * armado del payload que se envía. Fuente de verdad única: la UI de suscripción y la
 * documentación leen de aquí.
 *
 * El sobre (envelope) es estable: { event, delivery_id, occurred_at, data }. `data` trae
 * el recurso afectado ya serializado, para que el tercero no tenga que re-consultar
 * nuestra API. Cada tipo pertenece a un `group` para ordenarlos en la UI.
 */
class WebhookEvent
{
    // ── Eventos (ciclo de vida del evento) ────────────────────────────
    public const EVENT_CREATED = 'event.created';
    public const EVENT_UPDATED = 'event.updated';
    public const EVENT_STATUS_CHANGED = 'event.status_changed';
    public const EVENT_ASSIGNED = 'event.assigned';
    public const EVENT_COMMENT_ADDED = 'event.comment_added';
    public const EVENT_REPLY = 'event.comment_reply';
    public const EVENT_MENTION = 'event.mention';

    // ── Mantenimientos ────────────────────────────────────────────────
    public const MAINTENANCE_CREATED = 'maintenance.created';
    public const MAINTENANCE_UPDATED = 'maintenance.updated';

    // ── Actividades ───────────────────────────────────────────────────
    public const ACTIVITY_DOCUMENTED = 'activity.documented';

    /**
     * Escenarios ofrecidos al suscribir un webhook, agrupados para la UI.
     *
     * @return array<int,array{type:string,group:string,label:string,description:string}>
     */
    public static function catalog(): array
    {
        return [
            // Eventos
            ['type' => self::EVENT_CREATED,        'group' => 'Eventos', 'label' => 'Evento creado',        'description' => 'Cuando se crea un evento en el cliente/sitio suscrito.'],
            ['type' => self::EVENT_UPDATED,        'group' => 'Eventos', 'label' => 'Evento editado',       'description' => 'Cuando se edita un evento (descripción, prioridad, formulario, dispositivo).'],
            ['type' => self::EVENT_STATUS_CHANGED, 'group' => 'Eventos', 'label' => 'Cambio de estado',     'description' => 'Cuando un evento cambia de estado (incluye estado anterior y nuevo).'],
            ['type' => self::EVENT_ASSIGNED,       'group' => 'Eventos', 'label' => 'Evento asignado',      'description' => 'Cuando un evento se asigna, reasigna o se retira del pool.'],
            ['type' => self::EVENT_COMMENT_ADDED,  'group' => 'Eventos', 'label' => 'Comentario agregado',  'description' => 'Cuando se agrega cualquier comentario a un evento.'],
            ['type' => self::EVENT_REPLY,          'group' => 'Eventos', 'label' => 'Respuesta a comentario', 'description' => 'Cuando un comentario responde a otro (incluye el comentario padre).'],
            ['type' => self::EVENT_MENTION,        'group' => 'Eventos', 'label' => 'Mención (@)',          'description' => 'Cuando se menciona a uno o más usuarios en un comentario.'],

            // Mantenimientos
            ['type' => self::MAINTENANCE_CREATED,  'group' => 'Mantenimientos', 'label' => 'Mantenimiento registrado', 'description' => 'Cuando se registra un mantenimiento en un sitio.'],
            ['type' => self::MAINTENANCE_UPDATED,  'group' => 'Mantenimientos', 'label' => 'Mantenimiento actualizado', 'description' => 'Cuando cambia un mantenimiento (fechas, estado: programado/en curso/completado/cancelado).'],

            // Actividades
            ['type' => self::ACTIVITY_DOCUMENTED,  'group' => 'Actividades', 'label' => 'Actividad documentada', 'description' => 'Cuando se registra una actividad sobre un dispositivo dentro de un mantenimiento.'],
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

    /**
     * Serializa un mantenimiento para el payload del webhook.
     *
     * @return array<string,mixed>
     */
    public static function maintenanceData(Maintenance $maintenance, ?User $actor = null, array $extra = []): array
    {
        $maintenance->loadMissing(['system:id,label', 'site:id,name,client_id']);

        return array_merge([
            'id'         => $maintenance->id,
            'type'       => $maintenance->type,
            'status'     => $maintenance->status,
            'start_date' => optional($maintenance->start_date)->toDateString() ?? (string) $maintenance->start_date,
            'end_date'   => optional($maintenance->end_date)->toDateString() ?? (string) $maintenance->end_date,
            'site_id'    => $maintenance->site_id,
            'client_id'  => $maintenance->site?->client_id,
            'system'     => $maintenance->system ? ['id' => $maintenance->system->id, 'label' => $maintenance->system->label] : null,
            'notes'      => $maintenance->notes,
            'created_at' => $maintenance->created_at?->toISOString(),
            'actor'      => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
        ], $extra);
    }

    /**
     * Serializa una actividad de mantenimiento para el payload del webhook.
     *
     * @return array<string,mixed>
     */
    public static function activityData(MaintenanceActivity $activity, ?User $actor = null): array
    {
        $activity->loadMissing(['activityType:id,label', 'user:id,name', 'maintenance:id,site_id,catalog_id']);

        return [
            'id'             => $activity->id,
            'maintenance_id' => $activity->maintenance_id,
            'site_id'        => $activity->maintenance?->site_id,
            'system_id'      => $activity->maintenance?->catalog_id,
            'device_id'      => $activity->device_id,
            'activity_type'  => $activity->activityType ? ['id' => $activity->activityType->id, 'label' => $activity->activityType->label] : null,
            'field_values'   => $activity->field_values,
            'performed_at'   => $activity->performed_at?->toISOString(),
            'user'           => $activity->user ? ['id' => $activity->user->id, 'name' => $activity->user->name] : null,
            'created_at'     => $activity->created_at?->toISOString(),
            'actor'          => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
        ];
    }
}
