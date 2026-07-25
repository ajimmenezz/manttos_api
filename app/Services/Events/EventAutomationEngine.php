<?php

namespace App\Services\Events;

use App\Jobs\RunEventAutomation;
use App\Models\Event;
use App\Models\EventAutomationRun;
use App\Models\EventTypeAutomation;
use App\Support\FieldRuleEvaluator;

/**
 * Punto único de las automatizaciones de evento. Al ocurrir un momento del ciclo de vida de
 * un evento (created/documented/status_changed/assigned/comment_added), resuelve las
 * automatizaciones aplicables, evalúa sus condiciones y encola la ejecución de las que
 * coinciden.
 *
 * GARANTÍA a prueba de fallos: `run()` jamás lanza hacia la petición del negocio. Si algo
 * falla aquí, se reporta y la acción del evento (crear/documentar/…) sigue su curso.
 */
class EventAutomationEngine
{
    /**
     * @param  array<string,mixed>  $extra  contexto del disparo (p. ej. ['status_key' => 'resuelto'])
     */
    public function run(Event $event, string $lifecycle, ?int $actorId = null, array $extra = []): void
    {
        try {
            $automations = EventTypeAutomation::where('event_type_id', $event->event_type_id)
                ->where('system_id', $event->system_id)
                ->where('is_active', true)
                ->where('event', $lifecycle)
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            if ($automations->isEmpty()) {
                return;
            }

            $context = $this->buildContext($event, $extra);

            foreach ($automations as $automation) {
                // Filtro de estado destino (solo para status_changed).
                if ($lifecycle === 'status_changed'
                    && ! empty($automation->status_key)
                    && $automation->status_key !== ($extra['status_key'] ?? null)) {
                    continue;
                }

                // run_once: no re-disparar si ya corrió con éxito para este evento.
                if ($automation->run_once && $this->alreadyRan($automation->id, $event->id)) {
                    continue;
                }

                if (! FieldRuleEvaluator::matches($automation->trigger, $context)) {
                    continue;
                }

                RunEventAutomation::dispatch($automation->id, $event->id, $actorId);
            }
        } catch (\Throwable $e) {
            report($e); // nunca tumba el negocio
        }
    }

    /**
     * Contexto de evaluación por fuente. `form` = valores del formulario del evento;
     * `device` = custom_fields del dispositivo ligado (si hay); `event` = atributos del
     * propio evento (para condiciones tipo "prioridad = crítica").
     *
     * @return array<string,array<string,mixed>>
     */
    private function buildContext(Event $event, array $extra): array
    {
        $device = ($event->device && is_array($event->device->custom_fields))
            ? $event->device->custom_fields : [];

        return [
            'form'   => is_array($event->field_values) ? $event->field_values : [],
            'device' => $device,
            'event'  => [
                'priority'      => $event->priority,
                'impact'        => $event->impact,
                'urgency'       => $event->urgency,
                'status_key'    => $extra['status_key'] ?? optional($event->status)->key,
                'event_type_id' => (string) $event->event_type_id,
                'nature'        => optional($event->eventType)->nature,
            ],
        ];
    }

    private function alreadyRan(int $automationId, int $eventId): bool
    {
        return EventAutomationRun::where('event_automation_id', $automationId)
            ->where('event_id', $eventId)
            ->where('status', 'success')
            ->exists();
    }
}
