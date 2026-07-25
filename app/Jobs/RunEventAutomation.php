<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\EventAutomationRun;
use App\Models\EventComment;
use App\Models\EventStatus;
use App\Models\EventStatusHistory;
use App\Models\EventTypeAutomation;
use App\Models\IntegrationLink;
use App\Models\IntegrationLog;
use App\Services\Events\EventAutomationEngine;
use App\Services\Integrations\IntegrationManager;
use App\Support\EventFolio;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Ejecuta UNA automatización de evento de forma aislada (job encolado con reintentos). Según
 * `action_kind`: acción de integración (Odoo/Jira), consulta que rellena el evento, acción
 * interna (estado/asignar/comentar/notificar) o generación de un evento de seguimiento.
 *
 * A prueba de fallos: cualquier error queda registrado en event_automation_runs; el evento
 * original ya se guardó antes de encolar esto.
 */
class RunEventAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function __construct(
        public int $automationId,
        public int $eventId,
        public ?int $actorId = null,
    ) {}

    public function handle(IntegrationManager $manager): void
    {
        $automation = EventTypeAutomation::find($this->automationId);
        $event = Event::with(['device', 'status', 'eventType', 'client'])->find($this->eventId);
        if (! $automation || ! $event) {
            return;
        }

        // run_once: si ya corrió con éxito, no repetir (protege ante reintentos/carreras).
        if ($automation->run_once
            && EventAutomationRun::where('event_automation_id', $automation->id)
                ->where('event_id', $event->id)->where('status', 'success')->exists()) {
            return;
        }

        try {
            $result = match ($automation->action_kind) {
                'integration' => $this->runIntegration($manager, $automation, $event),
                'query'       => $this->runQuery($manager, $automation, $event),
                'internal'    => $this->runInternal($automation, $event),
                'event'       => $this->runFollowUpEvent($automation, $event),
                default       => ['status' => 'skipped', 'note' => 'action_kind desconocido'],
            };

            EventAutomationRun::create([
                'event_automation_id' => $automation->id,
                'event_id'            => $event->id,
                'status'              => 'success',
                'result'              => $result,
                'ran_at'              => now(),
            ]);
        } catch (\Throwable $e) {
            EventAutomationRun::create([
                'event_automation_id' => $automation->id,
                'event_id'            => $event->id,
                'status'              => 'failed',
                'error'               => Str::limit($e->getMessage(), 900, ''),
                'ran_at'              => now(),
            ]);
            if ($this->attempts() < $this->tries) {
                throw $e; // reintenta con backoff
            }
        }
    }

    // ── Acciones de integración (Odoo/Jira) ───────────────────────────

    private function runIntegration(IntegrationManager $manager, EventTypeAutomation $a, Event $event): array
    {
        $provider = $manager->provider((string) $a->provider);
        if (! $provider) {
            return ['status' => 'skipped', 'note' => 'proveedor no disponible'];
        }
        $config = $manager->resolveConfig((string) $a->provider, $event->client_id);
        if (! $config) {
            return ['status' => 'skipped', 'note' => 'integración inactiva o sin configurar'];
        }

        $params = $this->buildParams($a->params_map ?? [], $event);
        // Acciones con líneas (p. ej. cotización de Odoo con productos): se arman desde lines_map.
        if (! empty($a->lines_map)) {
            $params['order_lines'] = $this->buildLines($a->lines_map, $event);
        }
        $res = $provider->runAction((string) $a->action, $params, $config);

        // Bitácora de integración (reusa integration_logs).
        IntegrationLog::create([
            'integration_id' => $config->id,
            'provider'       => $a->provider,
            'client_id'      => $event->client_id,
            'direction'      => 'outbound',
            'event_type'     => 'automation:' . $a->action,
            'status'         => ($res['ok'] ?? false) ? 'success' : 'failed',
            'payload'        => $params,
            'response'       => $res,
            'error'          => ($res['ok'] ?? false) ? null : ($res['message'] ?? null),
            'delivered_at'   => now(),
        ]);

        // Si devolvió un documento externo (id/clave), lo ligamos al evento.
        $extId = $res['data']['id'] ?? ($res['data']['key'] ?? null);
        $extKey = $res['data']['key'] ?? ($res['data']['name'] ?? null);
        if ($extId || $extKey) {
            IntegrationLink::updateOrCreate(
                ['integration_id' => $config->id, 'local_type' => 'event', 'local_id' => $event->id],
                ['provider' => $a->provider, 'external_key' => $extKey ? (string) $extKey : null,
                 'external_id' => $extId ? (string) $extId : null, 'external_url' => $res['url'] ?? null],
            );
        }

        return ['status' => 'done', 'ok' => $res['ok'] ?? null, 'message' => $res['message'] ?? null, 'url' => $res['url'] ?? null];
    }

    // ── Consulta que rellena el evento ────────────────────────────────

    private function runQuery(IntegrationManager $manager, EventTypeAutomation $a, Event $event): array
    {
        $params = $this->buildParams($a->params_map ?? [], $event);
        $res = $manager->query((string) $a->provider, $event->client_id, (string) $a->action, $params);

        $target = trim((string) $a->result_target);
        $summary = $this->summarizeQuery($res);

        if ($target === 'comment' || $target === '') {
            $this->postSystemComment($event, "Consulta {$a->provider} · {$a->action}:\n{$summary}");
        } else {
            // Rellena un campo del formulario del evento con el resumen.
            $fv = is_array($event->field_values) ? $event->field_values : [];
            $fv[$target] = $summary;
            $event->update(['field_values' => $fv]);
        }

        return ['status' => 'done', 'result_target' => $target ?: 'comment', 'summary' => $summary];
    }

    // ── Acciones internas ─────────────────────────────────────────────

    private function runInternal(EventTypeAutomation $a, Event $event): array
    {
        $cfg = $a->internal_config ?? [];
        switch ($a->internal_action) {
            case 'change_status':
                $status = EventStatus::where('key', $cfg['status_key'] ?? null)->first();
                if (! $status) return ['status' => 'skipped', 'note' => 'estado destino no existe'];
                if ((int) $event->status_id === (int) $status->id) return ['status' => 'noop', 'note' => 'ya está en ese estado'];
                $from = $event->status_id;
                $event->update(['status_id' => $status->id]);
                EventStatusHistory::create([
                    'event_id' => $event->id, 'from_status_id' => $from, 'to_status_id' => $status->id,
                    'user_id' => $this->actorId, 'note' => $cfg['note'] ?? 'Cambio automático (automatización).',
                    'created_at' => now(),
                ]);
                return ['status' => 'done', 'to' => $status->key];

            case 'assign':
                $assignee = $cfg['assignee_id'] ?? null;
                $event->update(['assigned_to' => $assignee]);
                return ['status' => 'done', 'assigned_to' => $assignee];

            case 'comment':
                $body = $this->interpolate((string) ($cfg['template'] ?? ''), $event);
                $this->postSystemComment($event, $body !== '' ? $body : 'Comentario automático.');
                return ['status' => 'done'];

            case 'notify':
                $targets = array_filter([$event->assigned_to, $event->created_by, $cfg['notify_user_id'] ?? null]);
                if (! empty($targets)) {
                    app(Notifier::class)->send(
                        array_unique($targets),
                        'event_automation',
                        ['event_id' => $event->id, 'folio' => $event->folio],
                        $cfg['title'] ?? 'Automatización de evento',
                        $this->interpolate((string) ($cfg['template'] ?? 'Se ejecutó una automatización en el evento {folio}.'), $event),
                        $this->actorId,
                    );
                }
                return ['status' => 'done'];

            default:
                return ['status' => 'skipped', 'note' => 'acción interna desconocida'];
        }
    }

    // ── Evento de seguimiento ─────────────────────────────────────────

    private function runFollowUpEvent(EventTypeAutomation $a, Event $event): array
    {
        if (! $a->target_event_type_id || ! $event->client) {
            return ['status' => 'skipped', 'note' => 'sin tipo destino o cliente'];
        }
        $initial = EventStatus::where('is_initial', true)->orderBy('sort_order')->first();

        $fieldValues = [];
        foreach ($a->prefill ?? [] as $m) {
            $key = $m['target_field_key'] ?? null;
            if (! $key) continue;
            if (($m['mode'] ?? 'constant') === 'constant') {
                if (($m['value'] ?? '') !== '') $fieldValues[$key] = $m['value'];
            } else { // copy
                $src = $m['source'] ?? 'form';
                $ctx = $src === 'device'
                    ? (($event->device && is_array($event->device->custom_fields)) ? $event->device->custom_fields : [])
                    : (is_array($event->field_values) ? $event->field_values : []);
                $v = $ctx[$m['source_field_key'] ?? ''] ?? null;
                if ($v !== null && $v !== '') $fieldValues[$key] = $v;
            }
        }

        $new = Event::create([
            'folio'         => EventFolio::next($event->client),
            'client_id'     => $event->client_id,
            'site_id'       => $event->site_id,
            'system_id'     => $event->system_id,
            'event_type_id' => $a->target_event_type_id,
            'device_id'     => $event->device_id,
            'status_id'     => optional($initial)->id,
            'priority'      => $event->priority,
            'description'   => 'Generado automáticamente desde el evento ' . $event->folio,
            'field_values'  => $fieldValues,
            'created_by'    => $this->actorId ?? $event->created_by,
        ]);

        return ['status' => 'done', 'new_event_id' => $new->id, 'new_folio' => $new->folio];
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Construye los parámetros de la acción a partir del mapeo (constante o valor copiado
     * del formulario/directorio/atributos del evento).
     *
     * @param  array<int,array<string,mixed>>  $map
     * @return array<string,mixed>
     */
    private function buildParams(array $map, Event $event): array
    {
        $bags = $this->contextBags($event);
        $out = [];
        foreach ($map as $m) {
            $pk = $m['param_key'] ?? null;
            if (! $pk) continue;
            $out[$pk] = $this->resolveMapping($m, $bags);
        }
        return $out;
    }

    /**
     * Arma las líneas de un documento (p. ej. cotización) desde `lines_map`. Cada entrada del
     * mapa tiene sub-mapeos product/quantity/price/description (constante o campo del evento).
     *
     * @param  array<int,array<string,mixed>>  $linesMap
     * @return array<int,array<string,mixed>>
     */
    private function buildLines(array $linesMap, Event $event): array
    {
        $bags = $this->contextBags($event);
        $out = [];
        foreach ($linesMap as $line) {
            $row = [];
            foreach (['product', 'quantity', 'price', 'description'] as $slot) {
                if (isset($line[$slot]) && is_array($line[$slot])) {
                    $row[$slot] = $this->resolveMapping($line[$slot], $bags);
                }
            }
            // Solo se incluye la línea si resolvió un producto.
            if (($row['product'] ?? '') !== '') {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Bolsas de valores por fuente para resolver mapeos. @return array<string,array<string,mixed>> */
    private function contextBags(Event $event): array
    {
        return [
            'form'   => is_array($event->field_values) ? $event->field_values : [],
            'device' => ($event->device && is_array($event->device->custom_fields)) ? $event->device->custom_fields : [],
            'event'  => [
                'folio' => $event->folio, 'description' => $event->description,
                'priority' => $event->priority, 'status_key' => optional($event->status)->key,
                'event_id' => $event->id,
            ],
        ];
    }

    /** Resuelve un mapeo {mode:'constant'|'field', value?, source?, source_field_key?}. */
    private function resolveMapping(array $m, array $bags): mixed
    {
        if (($m['mode'] ?? 'constant') === 'constant') {
            return $m['value'] ?? '';
        }
        $src = $m['source'] ?? 'form';
        $bag = $bags[$src] ?? $bags['form'];
        return $bag[$m['source_field_key'] ?? ''] ?? '';
    }

    private function postSystemComment(Event $event, string $body): void
    {
        $userId = $this->actorId ?? $event->created_by;
        if (! $userId) return; // EventComment requiere autor
        EventComment::create(['event_id' => $event->id, 'user_id' => $userId, 'body' => $body]);
    }

    private function summarizeQuery(array $res): string
    {
        if (($res['available'] ?? true) === false) {
            return 'No disponible (' . ($res['reason'] ?? 'sin datos') . ').';
        }
        if (isset($res['message'])) return (string) $res['message'];
        if (isset($res['quantity'])) return 'Disponibles: ' . $res['quantity'];
        return Str::limit(json_encode($res, JSON_UNESCAPED_UNICODE), 500, '…');
    }

    private function interpolate(string $tpl, Event $event): string
    {
        return strtr($tpl, [
            '{folio}'       => (string) $event->folio,
            '{descripcion}' => (string) $event->description,
            '{prioridad}'   => (string) $event->priority,
        ]);
    }
}
