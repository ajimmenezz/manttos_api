<?php

namespace App\Services\Ai\Vision;

use App\Models\AiDocument;
use App\Models\Event;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\Rag\RagService;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnóstico INICIAL DE APOYO para un evento a partir de sus fotos + descripción,
 * cruzado con la base de conocimiento (RAG) del sistema. Es orientación para el
 * ingeniero, NO un veredicto: siempre se etiqueta como tal.
 *
 * Pipeline: (1) visión → extrae hallazgos de las imágenes; (2) RAG → recupera del
 * manual del sistema; (3) síntesis → causas/pasos/refacciones citando el manual.
 * Reutiliza VisionClient (provider-agnostic) y RagService (los mismos que ya existen).
 */
class EventDiagnosisService
{
    private const MAX_IMAGES = 4;
    private const MEDIA_DIR   = 'maintenance-media';

    private const DISCLAIMER = 'Diagnóstico de apoyo generado por IA a partir de las fotos y la descripción. Es orientativo: valida en sitio antes de actuar.';

    public function __construct(
        private VisionClient $vision,
        private RagService $rag,
    ) {}

    /** ¿Se puede diagnosticar este evento? (visión configurada + tiene imágenes). */
    public function canDiagnose(Event $event): bool
    {
        return $this->vision->isOperational() && ! empty($event->images);
    }

    /**
     * Genera y guarda el diagnóstico de apoyo. Devuelve el arreglo almacenado.
     */
    public function diagnose(Event $event): array
    {
        $images = $this->loadImages((array) $event->images);
        if ($images === []) {
            throw new \RuntimeException('No hay imágenes legibles en el evento para analizar.');
        }

        $event->loadMissing(['system:id,label', 'device:id,name,brand,model', 'eventType:id,name']);
        $started = microtime(true);

        // (1) Visión: extraer hallazgos de las fotos.
        $extract = $this->vision->analyze(
            $images,
            self::VISION_SYSTEM,
            $this->visionPrompt($event),
        );
        $hallazgos = $this->parseJson($extract['text']);

        // (2) RAG: recuperar del manual del sistema (colección de soporte).
        $knowledge = $this->retrieveKnowledge($event, $hallazgos);

        // (3) Síntesis: causas / pasos / refacciones citando el manual.
        $synth = $this->vision->analyze(
            [], // solo texto
            self::SYNTH_SYSTEM,
            $this->synthPrompt($event, $hallazgos, $knowledge),
        );
        $diag = $this->parseJson($synth['text']);

        $durationMs = (int) ((microtime(true) - $started) * 1000);
        $inTok  = ($extract['usage']['input']  ?? 0) + ($synth['usage']['input']  ?? 0);
        $outTok = ($extract['usage']['output'] ?? 0) + ($synth['usage']['output'] ?? 0);

        $stored = array_merge($diag, [
            'hallazgos'  => $hallazgos ?: null,
            'fuentes'    => $diag['fuentes'] ?? array_values(array_unique(array_map(fn ($k) => $k['document'], $knowledge))),
            'disclaimer' => self::DISCLAIMER,
            'meta'       => [
                'model'      => $extract['resolved']['model'] ?? null,
                'imagenes'   => count($images),
                'con_manual' => ! empty($knowledge),
            ],
        ]);

        $event->forceFill([
            'ai_diagnosis'    => $stored,
            'ai_diagnosis_at' => now(),
        ])->save();

        AiUsageLogger::log('vision', $extract['resolved'], ['input' => $inTok, 'output' => $outTok], [
            'user_id'     => $event->assigned_to ?? $event->created_by,
            'prompt'      => 'Diagnóstico de evento ' . $event->folio,
            'reply'       => $stored['resumen'] ?? null,
            'duration_ms' => $durationMs,
            'iterations'  => 2,
            'actions'     => ['event_id' => $event->id, 'folio' => $event->folio],
        ]);

        return $stored;
    }

    // ── Internos ─────────────────────────────────────────────────────

    /**
     * Lee los bytes de las imágenes del disco público (las subió MediaController).
     *
     * @return array<int,array{mime:string,data:string}>
     */
    private function loadImages(array $urls): array
    {
        $disk = Storage::disk('public');
        $out = [];
        foreach (array_slice($urls, 0, self::MAX_IMAGES) as $url) {
            $base = basename((string) parse_url((string) $url, PHP_URL_PATH));
            $rel  = self::MEDIA_DIR . '/' . $base;
            if ($base === '' || ! $disk->exists($rel)) {
                continue;
            }
            $bytes = $disk->get($rel);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $out[] = [
                'mime' => $this->mimeFor($base),
                'data' => base64_encode($bytes),
            ];
        }
        return $out;
    }

    private function mimeFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'image/jpeg',
        };
    }

    /** Recupera del manual del sistema, priorizando códigos de error/anomalías vistos. */
    private function retrieveKnowledge(Event $event, array $hallazgos): array
    {
        if (! $this->rag->isOperational()) {
            return [];
        }

        $signals = array_merge(
            (array) ($hallazgos['codigos_error'] ?? []),
            (array) ($hallazgos['anomalias'] ?? []),
            (array) ($hallazgos['indicadores'] ?? []),
        );
        $query = trim(
            ($event->system?->label ? $event->system->label . '. ' : '')
            . (string) $event->description
            . (($signals) ? ' ' . implode('. ', array_map('strval', $signals)) : '')
        );
        if ($query === '') {
            return [];
        }

        return $this->rag->search($query, 5, 0.15, [
            'collection' => AiDocument::COLLECTION_SUPPORT,
            'catalog_id' => $event->system_id,
            'client_id'  => $event->client_id,
        ]);
    }

    private function visionPrompt(Event $event): string
    {
        $dev = $event->device;
        $ctx = 'Sistema: ' . ($event->system?->label ?? '—')
            . ($dev ? ('. Equipo declarado: ' . trim(($dev->name ?? '') . ' ' . ($dev->brand ?? '') . ' ' . ($dev->model ?? ''))) : '')
            . '. Reporte del solicitante: "' . (string) $event->description . '".';

        return $ctx . "\n\nObserva las fotos y extrae SOLO lo que veas. Devuelve JSON:\n"
            . '{"equipos":[{"tipo":"","marca":"","modelo":"","serie":""}],'
            . '"indicadores":[],"codigos_error":[],"anomalias":[],'
            . '"calidad_imagen":"buena|regular|mala","observaciones":""}'
            . "\nNo inventes; si algo no se ve, déjalo vacío. Solo el JSON.";
    }

    private function synthPrompt(Event $event, array $hallazgos, array $knowledge): string
    {
        $manual = '';
        foreach ($knowledge as $k) {
            $manual .= "\n---\n[" . ($k['document'] ?? 'Manual') . ($k['heading'] ? ' › ' . $k['heading'] : '') . "]\n" . trim((string) $k['content']);
        }
        $manual = $manual !== '' ? $manual : '(sin coincidencias en el manual del sistema)';

        return 'Sistema: ' . ($event->system?->label ?? '—')
            . '. Reporte: "' . (string) $event->description . '".'
            . "\n\nHallazgos de las fotos (JSON):\n" . json_encode($hallazgos, JSON_UNESCAPED_UNICODE)
            . "\n\nFragmentos del manual del sistema:\n" . $manual
            . "\n\nCon base en lo anterior, redacta un diagnóstico de APOYO para el ingeniero (orientativo, no definitivo). "
            . "Prioriza lo respaldado por el manual y sé honesto con la incertidumbre. Devuelve JSON:\n"
            . '{"resumen":"","causas_probables":[{"causa":"","probabilidad":"alta|media|baja"}],'
            . '"pasos_sugeridos":[],"refacciones_posibles":[],'
            . '"severidad":"alta|media|baja","confianza":"alta|media|baja","requiere_sitio":true,"fuentes":[]}'
            . "\nEn \"fuentes\" cita los títulos de manual usados. Solo el JSON.";
    }

    /** Extrae el objeto JSON de la respuesta del modelo (tolera envoltura ```json). */
    private function parseJson(string $text): array
    {
        $t = trim($text);
        if (preg_match('/^```[a-zA-Z0-9]*\s*\R(.*?)\R?```$/s', $t, $m)) {
            $t = trim($m[1]);
        }
        // Recorta a las llaves externas por si el modelo agregó texto.
        $start = strpos($t, '{');
        $end   = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $t = substr($t, $start, $end - $start + 1);
        }
        $data = json_decode($t, true);
        return is_array($data) ? $data : [];
    }

    private const VISION_SYSTEM = 'Eres un técnico de sistemas de seguridad electrónica (CCTV, control de acceso, alarmas, detección de incendio). Analizas fotos de equipos en sitio y describes objetivamente lo que se observa. No inventas datos que no sean visibles.';

    private const SYNTH_SYSTEM = 'Eres un ingeniero de soporte de sistemas de seguridad electrónica. Das diagnósticos iniciales de apoyo, claros y accionables, basados en la evidencia y el manual. Reconoces la incertidumbre y nunca presentas el diagnóstico como definitivo.';
}
