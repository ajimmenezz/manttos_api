<?php

namespace App\Services\Capture;

use App\Models\Catalog;
use App\Models\CaptureConversation;
use App\Models\CaptureMessage;
use App\Models\Event;
use App\Models\Site;
use App\Services\Ai\Rag\RagService;
use App\Services\Ai\Vision\EventDiagnosisService;

/**
 * Motor del SIMULADOR del agente de captación: corre el MISMO CaptureAgent +
 * recuperación de conocimiento + soporte de 1er nivel sobre una conversación
 * SANDBOX (marcada `is_simulation`), sin enviar nada por WhatsApp/Telegram. La
 * creación del evento es DRY-RUN por defecto (muestra el ticket que se crearía)
 * y opcionalmente real. El consumo se registra en el Registro IA con origen `tester`.
 */
class SimulatorService
{
    public function __construct(
        private CaptureAgent $agent,
        private EventCreator $creator,
        private EventDiagnosisService $diagnosis,
    ) {}

    /**
     * Procesa un turno del simulador y devuelve todo lo que el tester necesita ver:
     * respuesta, decisión, conocimiento usado, tokens y el ticket (simulado o real).
     *
     * @param  array<int,string>  $imageUrls  fotos adjuntas al mensaje simulado (URLs públicas)
     */
    public function turn(CaptureConversation $conv, string $text, bool $createReal = false, array $imageUrls = []): array
    {
        $channel = $conv->channel;
        $imageUrls = array_values(array_filter($imageUrls, fn ($u) => is_string($u) && $u !== ''));

        // Guarda el entrante (las fotos viajan en el payload, como en la bandeja real).
        CaptureMessage::create([
            'conversation_id' => $conv->id, 'channel_id' => $channel->id,
            'direction' => 'in', 'body' => $text,
            'payload' => $imageUrls ? ['images' => $imageUrls] : null,
            'created_at' => now(),
        ]);
        $conv->update(['last_inbound_at' => now(), 'last_message_at' => now()]);

        $contact = $conv->contact;

        // Línea "solo registrados": si el contacto simulado no se identifica, corta.
        $identified = $contact->user_id || $contact->client_id || $contact->site_id;
        if ($channel->require_registered && ! $identified) {
            $reply = 'Hola 👋 Para atender tu solicitud necesito que estés registrado. Por favor contacta a tu administrador.';
            $this->out($conv, $reply);
            return $this->result($conv, $reply, null, [], ['input' => 0, 'output' => 0], null, null, true);
        }

        // Agente (consumo etiquetado como 'tester'). Ve las fotos de este turno (visión).
        $decision = $this->agent->respond($conv->fresh('messages'), 'tester', $imageUrls);
        $reply = $decision['reply'];

        // Fotos PENDIENTES del reporte (acumuladas turno a turno hasta crear el evento).
        $pendingImages = array_values(array_unique(array_merge(
            array_map('strval', (array) ($conv->state['pending_images'] ?? [])),
            $imageUrls,
        )));

        // Resolución / desambiguación de dispositivo (misma lógica que la bandeja real).
        $deviceId = null; $defer = false; $keepCandidates = null; $candidates = [];
        if (! empty($decision['device_id']) && ! empty($conv->state['device_candidates'])) {
            $deviceId = (int) $decision['device_id'];
        } elseif (! empty($decision['device_hint']) && $decision['ready'] && $decision['site_id'] && $decision['system_id']) {
            $candidates = $this->creator->deviceCandidates((int) $decision['site_id'], (int) $decision['system_id'], (string) $decision['device_hint']);
            if (count($candidates) === 1) {
                $deviceId = (int) $candidates[0]['id'];
            } elseif (count($candidates) >= 2) {
                $defer = true; $keepCandidates = $candidates;
                $names = collect($candidates)->pluck('name')->implode(', ');
                $reply = "Encontré varios equipos parecidos a \"{$decision['device_hint']}\": {$names}. ¿Cuál es? Dime el nombre o responde \"ninguno\".";
            }
        }

        $ticketKey  = 'sim-' . $conv->id . '-' . substr(sha1(($decision['site_id'] ?? '') . '|' . ($decision['system_id'] ?? '') . '|' . ($decision['description'] ?? '')), 0, 20);
        $lastKey    = $conv->state['last_ticket_key'] ?? null;
        $createdKey = $lastKey;

        $simulatedTicket = null; $createdEvent = null; $diagnosis = null;
        $wouldCreate = ! $defer && empty($decision['resolved']) && $decision['ready']
            && $decision['site_id'] && $decision['system_id'] && $decision['description'] && $ticketKey !== $lastKey;

        if ($wouldCreate) {
            $payload = $this->ticketPayload($decision, $deviceId, $candidates);
            $payload['imagenes'] = count($pendingImages); // cuántas fotos se atarían

            if ($createReal) {
                $result = $this->creator->create($channel, (int) $decision['site_id'], (int) $decision['system_id'],
                    (string) $decision['description'], $decision['priority'] ?? null, $ticketKey, $contact->user, $deviceId, $pendingImages);
                if ($result['ok']) {
                    $createdEvent = ['folio' => $result['folio'] ?? null, 'event_id' => $result['event_id'] ?? null];
                    $conv->event_id = $result['event_id'] ?? null;
                    $createdKey = $ticketKey;
                    $pendingImages = []; // atadas al evento
                    $reply = ($result['folio'] ?? false)
                        ? "✅ Listo, tu reporte quedó registrado con folio {$result['folio']}. Un técnico lo atenderá pronto."
                        : '✅ Listo, tu reporte quedó registrado.';
                    CaptureMessage::create([
                        'conversation_id' => $conv->id, 'channel_id' => $channel->id, 'direction' => 'system',
                        'body' => $result['folio'] ? "Evento {$result['folio']} generado (simulador)" : 'Evento generado (simulador)',
                        'payload' => ['type' => 'event_created', 'event_id' => $result['event_id'] ?? null, 'folio' => $result['folio'] ?? null],
                        'created_at' => now(),
                    ]);

                    // Diagnóstico por foto del evento recién creado (para verlo en el simulador).
                    $diagnosis = $this->diagnoseCreated($result['event_id'] ?? null);
                } else {
                    $reply = 'Tuve un problema al registrar tu reporte. Detalle: ' . ($result['error'] ?? '');
                }
            } else {
                // DRY-RUN: no se crea nada; se muestra lo que se crearía.
                $simulatedTicket = $payload;
                $createdKey = $ticketKey; // no re-simular la misma firma en cada turno
                $imgNote = $pendingImages ? ' Se adjuntarían ' . count($pendingImages) . ' foto(s) y correría el diagnóstico por foto.' : '';
                $reply = "🧪 (Simulación) Con esto se crearía el evento: {$payload['sistema']} en {$payload['sitio']}.{$imgNote} "
                    . 'En una conversación real, aquí se generaría el folio. Usa "Crear de verdad" si quieres levantarlo.';
            }
        }

        // Persistir estado (conserva sitio/sistema entre turnos, como la bandeja real).
        $state = [
            'is_ticket'       => $decision['is_ticket'],
            'site_id'         => $decision['site_id'] ?? ($conv->state['site_id'] ?? null),
            'system_id'       => $decision['system_id'] ?? ($conv->state['system_id'] ?? null),
            'description'     => $decision['description'],
            'priority'        => $decision['priority'],
            'last_ticket_key' => $createdKey,
        ];
        if ($keepCandidates) $state['device_candidates'] = $keepCandidates;
        if ($pendingImages) $state['pending_images'] = $pendingImages;
        $conv->fill(['state' => $state, 'last_message_at' => now()]);
        if (! empty($decision['memory'])) $conv->context_summary = $decision['memory'];
        $conv->save();

        $this->out($conv, $reply);

        $out = $this->result($conv, $reply, $decision, $candidates, $decision['usage'] ?? ['input' => 0, 'output' => 0], $simulatedTicket, $createdEvent);
        $out['photo_observation'] = $decision['photo_observation'] ?? null;
        $out['diagnosis'] = $diagnosis;

        return $out;
    }

    // ── Internos ─────────────────────────────────────────────────────

    /** Corre el diagnóstico por foto del evento recién creado; null si no aplica/falla. */
    private function diagnoseCreated(?int $eventId): ?array
    {
        if (! $eventId) {
            return null;
        }
        $event = Event::find($eventId);
        if (! $event || ! $this->diagnosis->canDiagnose($event)) {
            return null;
        }
        try {
            return $this->diagnosis->diagnose($event);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Datos legibles del ticket que se crearía (dry-run). */
    private function ticketPayload(array $decision, ?int $deviceId, array $candidates): array
    {
        $site   = Site::find($decision['site_id']);
        $system = Catalog::find($decision['system_id']);
        $device = null;
        if ($deviceId) {
            $device = collect($candidates)->firstWhere('id', $deviceId)['name']
                ?? optional(\App\Models\Directory::find($deviceId))->name;
        }
        return [
            'sitio'       => optional($site)->name,
            'sistema'     => optional($system)->label,
            'descripcion' => $decision['description'],
            'prioridad'   => $decision['priority'] ?? 'media',
            'dispositivo' => $device,
        ];
    }

    /** Conocimiento (RAG) disponible para el sistema en curso, para el panel de depuración. */
    private function knowledgeUsed(CaptureConversation $conv, array $decision): array
    {
        if ($conv->channel->supportMode() === \App\Models\Channel::SUPPORT_OFF) return [];
        $systemId = $decision['system_id'] ?? ($conv->state['system_id'] ?? null);
        $query = $this->lastInbound($conv);
        if (! $systemId || $query === '') return [];

        $rag = app(RagService::class);
        if (! $rag->isOperational()) return [];
        try {
            $hits = $rag->search($query, topK: 5, minScore: 0.12, filters: [
                'collection' => \App\Models\AiDocument::COLLECTION_SUPPORT,
                'catalog_id' => (int) $systemId,
                'client_id'  => optional($conv->contact)->client_id,
            ]);
        } catch (\Throwable) { return []; }

        return collect($hits)->map(fn ($h) => [
            'heading'  => $h['heading'],
            'snippet'  => \Illuminate\Support\Str::limit(trim((string) $h['content']), 200),
            'score'    => round((float) $h['score'], 3),
            'audience' => $h['audience'] ?? 'support',
        ])->all();
    }

    private function lastInbound(CaptureConversation $conv): string
    {
        $m = $conv->messages()->where('direction', 'in')->reorder('created_at', 'desc')->first();
        return trim((string) ($m->body ?? ''));
    }

    private function out(CaptureConversation $conv, string $reply): void
    {
        CaptureMessage::create([
            'conversation_id' => $conv->id, 'channel_id' => $conv->channel_id,
            'direction' => 'out', 'body' => $reply, 'created_at' => now(),
        ]);
    }

    private function result(CaptureConversation $conv, string $reply, ?array $decision, array $candidates, array $usage, ?array $simulatedTicket, ?array $createdEvent, bool $blocked = false): array
    {
        return [
            'reply'    => $reply,
            'blocked'  => $blocked,
            'decision' => $decision ? [
                'is_ticket'   => (bool) ($decision['is_ticket'] ?? false),
                'site_id'     => $decision['site_id'] ?? null,
                'system_id'   => $decision['system_id'] ?? null,
                'description' => $decision['description'] ?? null,
                'priority'    => $decision['priority'] ?? null,
                'device_hint' => $decision['device_hint'] ?? null,
                'device_id'   => $decision['device_id'] ?? null,
                'ready'       => (bool) ($decision['ready'] ?? false),
                'resolved'    => (bool) ($decision['resolved'] ?? false),
            ] : null,
            'device_candidates' => array_values($candidates),
            'knowledge_used'    => $decision ? $this->knowledgeUsed($conv, $decision) : [],
            'usage'             => $usage,
            'simulated_ticket'  => $simulatedTicket,
            'created_event'     => $createdEvent,
        ];
    }
}
