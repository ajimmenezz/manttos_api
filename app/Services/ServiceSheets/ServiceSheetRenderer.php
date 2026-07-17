<?php

namespace App\Services\ServiceSheets;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventTypeField;
use App\Models\SystemField;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el PDF de la hoja de servicio de un evento (equivalente server-side de la
 * página web events/[id]/hoja). Reúne los mismos datos (generales, dispositivo +
 * directorio, formulario, historial, comentarios, firmas), incrusta las imágenes como
 * data-URI (para que dompdf las muestre sin red) y renderiza la vista Blade a PDF.
 */
class ServiceSheetRenderer
{
    private const PRIORITY = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];
    private const IMPACT   = ['alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo'];
    private const URGENCY  = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];

    /** Devuelve los bytes del PDF de la hoja de servicio del evento. */
    public function renderPdf(Event $event, array $branding = []): string
    {
        $data = $this->buildData($event, $branding);

        return Pdf::loadView('pdf.service-sheet', $data)
            ->setPaper('letter')
            ->output();
    }

    private function buildData(Event $event, array $branding): array
    {
        $event->load([
            'eventType', 'system:id,label', 'status', 'site:id,name,client_id',
            'client:id,name,short_name', 'device:id,name,device_type,location,custom_fields', 'creator:id,name',
            'history.toStatus:id,label,color', 'history.fromStatus:id,label', 'history.user:id,name',
        ]);

        $fields = EventTypeField::where('event_type_id', $event->event_type_id)
            ->where('system_id', $event->system_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $formFields = $fields->filter(fn ($f) => ! in_array($f->field_type, ['leyenda', 'signature'], true))->values();
        $signatureFields = $fields->filter(fn ($f) => $f->field_type === 'signature')->values();

        // Campos del directorio (base + override por cliente) + clave del DID.
        $dirFields = $this->directoryFieldDefs($event->system_id, $event->client_id);
        $didKey = collect($dirFields)->firstWhere('field_type', 'did')['field_key'] ?? 'did';
        $cf = is_array($event->device?->custom_fields) ? $event->device->custom_fields : [];
        $dirEntries = collect($dirFields)
            ->filter(fn ($f) => $f['field_key'] !== $didKey && ($cf[$f['field_key']] ?? null) !== null && ($cf[$f['field_key']] ?? '') !== '')
            ->map(fn ($f) => ['label' => $f['label'], 'value' => $this->renderValue($cf[$f['field_key']] ?? null)])
            ->values()->all();

        $values = is_array($event->field_values) ? $event->field_values : [];
        $formRows = $formFields->map(fn ($f) => [
            'label' => $f->label,
            'value' => $this->renderValue($values[$f->field_key] ?? null),
        ])->all();

        $signatures = $signatureFields->map(function ($f) use ($values) {
            $v = $values[$f->field_key] ?? null;
            $src = is_array($v) ? collect($v)->first(fn ($x) => $this->isImageUrl($x)) : ($this->isImageUrl($v) ? $v : null);
            return ['label' => $f->label, 'image' => $src ? $this->dataUri($src) : null];
        })->all();

        $comments = EventComment::where('event_id', $event->id) // SoftDeletes ya excluye borrados
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => [
                'user' => $c->user->name ?? '—',
                'date' => optional($c->created_at)->format('d/m/Y H:i'),
                'body' => $this->plainBody((string) $c->body),
            ])->all();

        $history = $event->history->sortBy('created_at')->map(fn ($h) => [
            'from'  => optional($h->fromStatus)->label,
            'to'    => optional($h->toStatus)->label,
            'date'  => optional($h->created_at)->format('d/m/Y H:i'),
            'user'  => optional($h->user)->name ?? '—',
            'note'  => trim((string) $h->note) ?: null,
        ])->values()->all();

        return [
            'appName'  => $branding['app_name'] ?? 'Mantenimientos',
            'logo'     => isset($branding['logo_url']) && $branding['logo_url'] ? $this->dataUri($branding['logo_url']) : null,
            'folio'    => $event->folio,
            'status'   => ['label' => optional($event->status)->label, 'color' => optional($event->status)->color],
            'general'  => [
                'cliente'      => optional($event->client)->name,
                'sitio'        => optional($event->site)->name,
                'sistema'      => optional($event->system)->label,
                'tipo'         => optional($event->eventType)->label,
                'naturaleza'   => optional($event->eventType)->nature,
                'prioridad'    => self::PRIORITY[$event->priority] ?? $event->priority,
                'estado'       => optional($event->status)->label,
                'impacto'      => $event->impact ? (self::IMPACT[$event->impact] ?? $event->impact) : null,
                'urgencia'     => $event->urgency ? (self::URGENCY[$event->urgency] ?? $event->urgency) : null,
                'ocurrencia'   => optional($event->occurred_at)->format('d/m/Y'),
                'creado_por'   => optional($event->creator)->name,
                'creado'       => optional($event->created_at)->format('d/m/Y H:i'),
                'descripcion'  => $event->description,
            ],
            'device'   => $event->device ? [
                'did'      => ($cf[$didKey] ?? '') !== '' ? (string) ($cf[$didKey] ?? '') : null,
                'nombre'   => $event->device->name,
                'tipo'     => $event->device->device_type,
                'ubicacion' => $event->device->location,
            ] : null,
            'dirEntries' => $dirEntries,
            'formRows'   => $formRows,
            'history'    => $history,
            'comments'   => $comments,
            'signatures' => $signatures,
            'generatedAt' => now()->format('d/m/Y'),
        ];
    }

    /** Campos activos del directorio de un sistema (base + override por cliente), ordenados. */
    private function directoryFieldDefs(int $systemId, ?int $clientId): array
    {
        $rows = SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)),
                fn ($q) => $q->whereNull('client_id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'client_id', 'field_key', 'label', 'field_type', 'sort_order']);

        return $rows->sortBy(fn ($f) => $f->client_id === null ? 0 : 1)
            ->keyBy('field_key')
            ->sortBy('sort_order')
            ->map(fn ($f) => ['field_key' => $f->field_key, 'label' => $f->label, 'field_type' => $f->field_type])
            ->values()->all();
    }

    /** Valor legible; si es imagen(es), devuelve arreglo de data-URIs para incrustar. */
    private function renderValue($v): array|string
    {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'Sí' : 'No';
        if (is_array($v)) {
            $imgs = array_values(array_filter($v, fn ($x) => $this->isImageUrl($x)));
            if ($imgs) {
                return ['images' => array_map(fn ($u) => $this->dataUri($u), $imgs)];
            }
            return implode(', ', array_map('strval', $v));
        }
        if ($this->isImageUrl($v)) {
            return ['images' => [$this->dataUri($v)]];
        }
        return (string) $v;
    }

    private function isImageUrl($v): bool
    {
        return is_string($v)
            && preg_match('#^https?://#', $v)
            && preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', explode('?', $v)[0]);
    }

    /** Convierte una URL pública (maintenance-media/...) a data-URI leyendo el archivo local. */
    private function dataUri(string $url): ?string
    {
        try {
            $base = basename((string) parse_url($url, PHP_URL_PATH));
            $disk = Storage::disk('public');
            foreach (["maintenance-media/{$base}", $base] as $path) {
                if ($disk->exists($path)) {
                    $bytes = $disk->get($path);
                    $mime  = $disk->mimeType($path) ?: 'image/jpeg';
                    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
                }
            }
        } catch (\Throwable) {
            // ignora imágenes ilegibles
        }
        return null;
    }

    /** Vuelve legibles las @menciones del cuerpo (formato canónico `@[Nombre](id)`). */
    private function plainBody(string $body): string
    {
        return trim(preg_replace('/@\[([^\]]+)\]\(\d+\)/', '@$1', $body));
    }
}
