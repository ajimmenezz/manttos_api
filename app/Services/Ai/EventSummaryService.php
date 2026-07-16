<?php

namespace App\Services\Ai;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventTypeField;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Resumen de IA" del evento: sintetiza TODO el evento (datos generales, formulario,
 * historial de estados con notas, comentarios, fotos y diagnóstico) en un texto breve
 * para el detalle web y para que el agente de captación responda estatus sin
 * reconstruir. Provider-agnostic (mismo estilo raw-HTTP del stack). Se regenera solo
 * cuando está DESACTUALIZADO (events.ai_summary_stale); guarda con saveQuietly para
 * no re-marcarse a sí mismo.
 */
class EventSummaryService
{
    /** ¿Hay IA utilizable para generar resúmenes? */
    public function isOperational(): bool
    {
        $r = AiSettings::resolved();

        return ($r['enabled'] ?? false) && ! empty($r['model'])
            && (! empty($r['local']) || ! empty($r['api_key']));
    }

    /**
     * Genera (y guarda) el resumen del evento. Devuelve el texto.
     */
    public function summarize(Event $event): string
    {
        $resolved = AiSettings::resolved();
        if (! $this->isOperational()) {
            throw new \RuntimeException('La IA no está configurada. Actívala en Ajustes → Asistente IA.');
        }

        $started = microtime(true);
        [$text, $usage] = $this->complete(self::SYSTEM, $this->prompt($event), $resolved);
        $summary = trim($text);
        if ($summary === '') {
            throw new \RuntimeException('La IA no devolvió un resumen.');
        }

        $event->forceFill([
            'ai_summary'       => $summary,
            'ai_summary_at'    => now(),
            'ai_summary_stale' => false,
        ])->saveQuietly();

        AiUsageLogger::log('event-summary', $resolved, $usage, [
            'user_id'     => $event->assigned_to ?? $event->created_by,
            'prompt'      => 'Resumen de evento ' . $event->folio,
            'reply'       => Str::limit($summary, 300),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'actions'     => ['event_id' => $event->id, 'folio' => $event->folio],
        ]);

        return $summary;
    }

    // ── Contexto ─────────────────────────────────────────────────────

    private function prompt(Event $event): string
    {
        $event->loadMissing([
            'status:id,label', 'eventType:id,label', 'site:id,name', 'client:id,name,short_name',
            'system:id,label', 'device:id,name,brand,model', 'creator:id,name', 'assignee:id,name',
            'history.toStatus:id,label', 'history.fromStatus:id,label', 'history.user:id,name',
        ]);

        $device = null;
        if ($event->device) {
            $device = trim($event->device->name . ' ' . trim(((string) $event->device->brand) . ' ' . ((string) $event->device->model)));
        }

        $general = array_filter([
            'folio'              => $event->folio,
            'tipo'               => optional($event->eventType)->label,
            'estado_actual'      => optional($event->status)->label,
            'prioridad'          => $event->priority,
            'impacto'            => $event->impact,
            'urgencia'           => $event->urgency,
            'cliente'            => optional($event->client)->short_name ?: optional($event->client)->name,
            'sitio'              => optional($event->site)->name,
            'sistema'            => optional($event->system)->label,
            'dispositivo'        => $device,
            'reportado_por'      => optional($event->creator)->name,
            'atiende'            => optional($event->assignee)->name,
            'fecha_reporte'      => optional($event->created_at)->format('Y-m-d H:i'),
            'fecha_ocurrencia'   => optional($event->occurred_at)->format('Y-m-d'),
            'atencion_programada' => optional($event->scheduled_attention_at)->format('Y-m-d H:i'),
            'descripcion'        => $event->description,
            'fotos_adjuntas'     => count((array) $event->images) ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        // Campos del formulario dinámico, con su etiqueta legible.
        $fields = [];
        $meta = EventTypeField::where('event_type_id', $event->event_type_id)
            ->get(['field_key', 'label', 'field_type'])->keyBy('field_key');
        foreach ((array) $event->field_values as $key => $val) {
            if ($val === null || $val === '' || $val === []) continue;
            $m = $meta->get($key);
            $label = $m->label ?? $key;
            if (($m->field_type ?? null) === 'boolean') {
                $val = in_array($val, [true, 1, '1', 'true', 'sí', 'si'], true) ? 'Sí' : 'No';
            } elseif (is_array($val)) {
                $val = implode(', ', array_map('strval', $val));
            }
            $fields[(string) $label] = (string) $val;
        }

        // Historial de estados (con notas de avance).
        $history = $event->history->sortBy('created_at')->take(-20)->map(fn ($h) => array_filter([
            'fecha'  => optional($h->created_at)->format('Y-m-d H:i'),
            'cambio' => trim((optional($h->fromStatus)->label ?? 'inicio') . ' → ' . (optional($h->toStatus)->label ?? '')),
            'por'    => optional($h->user)->name,
            'nota'   => trim((string) $h->note) ?: null,
        ]))->values()->all();

        // Comentarios/conversación del evento (los más recientes).
        $comments = EventComment::where('event_id', $event->id)
            ->with('user:id,name')->orderByDesc('created_at')->limit(15)->get()
            ->sortBy('created_at')->map(fn ($c) => [
                'fecha' => optional($c->created_at)->format('Y-m-d H:i'),
                'por'   => optional($c->user)->name,
                'texto' => Str::limit(trim((string) $c->body), 300),
            ])->values()->all();

        // Diagnóstico por foto (si existe): solo lo esencial.
        $diag = null;
        if (is_array($event->ai_diagnosis)) {
            $diag = array_filter([
                'resumen'   => $event->ai_diagnosis['resumen'] ?? null,
                'severidad' => $event->ai_diagnosis['severidad'] ?? null,
                'confianza' => $event->ai_diagnosis['confianza'] ?? null,
            ]);
        }

        $payload = json_encode(array_filter([
            'general'          => $general,
            'formulario'       => $fields ?: null,
            'historial'        => $history ?: null,
            'comentarios'      => $comments ?: null,
            'diagnostico_ia'   => $diag ?: null,
        ]), JSON_UNESCAPED_UNICODE);

        return "Datos completos del evento:\n{$payload}\n\n"
            . "Redacta el RESUMEN del evento en texto plano (sin JSON, sin markdown, sin encabezados).";
    }

    /** @return array{0:string,1:array{input:int,output:int}} */
    private function complete(string $system, string $prompt, array $resolved): array
    {
        if (($resolved['api_style'] ?? 'openai') === 'anthropic') {
            $provider = ChatProviderFactory::make($resolved, ToolRegistry::make());
            $res = $provider->chat([['role' => 'user', 'content' => $prompt]], [], $system);
            return [(string) ($res['content'] ?? ''), [
                'input'  => (int) ($res['usage']['input'] ?? 0),
                'output' => (int) ($res['usage']['output'] ?? 0),
            ]];
        }

        $req = Http::baseUrl(rtrim((string) ($resolved['base_url'] ?: 'https://api.openai.com/v1'), '/'))
            ->timeout(60)->acceptJson();
        if (! empty($resolved['api_key'])) {
            $req = $req->withToken($resolved['api_key']);
        }

        $res = $req->post('/chat/completions', [
            'model'       => $resolved['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 500,
            'stream'      => false,
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('IA (' . $res->status() . '): ' . $res->body());
        }

        $usage = $res->json('usage') ?? [];
        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }

    private const SYSTEM = <<<'PROMPT'
    Eres el asistente de una plataforma de mantenimiento de sistemas de seguridad electrónica.
    Redactas RESÚMENES EJECUTIVOS de eventos (reportes de falla / solicitudes de servicio).

    Reglas del resumen:
    - Español, texto plano (sin markdown, sin listas con viñetas, sin encabezados), 60-130 palabras.
    - Estructura natural en 1-2 párrafos: qué se reportó y dónde; estado actual y cómo llegó ahí
      (movimientos clave); últimos avances, acuerdos o notas importantes; datos capturados
      relevantes del formulario; y qué sigue o está pendiente, si se sabe.
    - Usa SOLO la información provista; no inventes nada ni supongas causas.
    - Menciona fechas solo cuando aporten (p. ej. el último avance).
    - Tono profesional y directo; nada de "en resumen" ni fórmulas de relleno.
    PROMPT;
}
