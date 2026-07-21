<?php

namespace App\Support;

/**
 * Catálogo central de los escenarios de notificación (bandeja in-app + push).
 *
 * Es la fuente de verdad única: define qué escenarios existen, cómo se agrupan y
 * describen para la pantalla de preferencias del usuario, y su valor por defecto. Tanto
 * el backend (validación y defaults) como la app (lista de switches) leen de aquí, así
 * agregar un escenario nuevo es tocar un solo lugar.
 *
 * Regla de las preferencias: la AUSENCIA de fila en notification_preferences significa
 * "encendido" (el default del catálogo). Solo se guarda fila cuando el usuario apaga —o
 * vuelve a prender— algo. Así un escenario nuevo llega encendido para todos sin migrar.
 */
class NotificationType
{
    public const EVENT_CREATED = 'event_created';
    public const EVENT_STATUS_CHANGED = 'event_status_changed';
    public const EVENT_COMMENT = 'event_comment';
    public const EVENT_REPLY = 'event_reply';
    public const EVENT_MENTION = 'event_mention';
    public const EVENT_ASSIGNED = 'event_assigned';
    public const CHAT_MESSAGE = 'chat_message';

    /**
     * Escenarios ofrecidos al usuario en Ajustes → Notificaciones.
     *
     * @return array<int,array{type:string,group:string,label:string,description:string,default:bool}>
     */
    public static function catalog(): array
    {
        return [
            ['type' => self::EVENT_CREATED,        'group' => 'Eventos', 'label' => 'Nuevos eventos',                'description' => 'Cuando se crea un evento en un cliente o sitio a tu cargo.', 'default' => true],
            ['type' => self::EVENT_STATUS_CHANGED, 'group' => 'Eventos', 'label' => 'Cambios de estado',             'description' => 'Cuando un evento que te interesa cambia de estado.',         'default' => true],
            ['type' => self::EVENT_COMMENT,        'group' => 'Eventos', 'label' => 'Comentarios en tus eventos',    'description' => 'Cuando alguien comenta un evento que creaste.',              'default' => true],
            ['type' => self::EVENT_REPLY,          'group' => 'Eventos', 'label' => 'Respuestas a tus comentarios',  'description' => 'Cuando responden a un comentario tuyo.',                     'default' => true],
            ['type' => self::EVENT_MENTION,        'group' => 'Eventos', 'label' => 'Menciones',                     'description' => 'Cuando te mencionan (@) en un evento.',                      'default' => true],
            ['type' => self::EVENT_ASSIGNED,       'group' => 'Eventos', 'label' => 'Asignaciones',                  'description' => 'Cuando te asignan un evento para atender.',                  'default' => true],
            ['type' => self::CHAT_MESSAGE,         'group' => 'Chat',    'label' => 'Mensajes de chat',              'description' => 'Mensajes directos y de grupo.',                              'default' => true],
        ];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_column(self::catalog(), 'type');
    }

    /** Default (encendido/apagado) de un tipo; true si el tipo no está en el catálogo. */
    public static function defaultFor(string $type): bool
    {
        foreach (self::catalog() as $entry) {
            if ($entry['type'] === $type) {
                return $entry['default'];
            }
        }

        return true;
    }
}
