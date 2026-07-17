<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\Event;
use App\Models\Notification;
use App\Models\ServiceSheetExport;
use App\Services\ServiceSheets\ServiceSheetRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera en segundo plano el ZIP con las hojas de servicio (PDF) de todos los eventos de
 * un cliente en un rango de fechas, y al terminar notifica al solicitante con el enlace
 * de descarga. Se procesa por la cola (cron que la drena en el server).
 */
class GenerateServiceSheetsZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tiempo máximo del job (segundos). */
    public int $timeout = 900;

    public function __construct(public int $exportId) {}

    public function handle(ServiceSheetRenderer $renderer): void
    {
        $export = ServiceSheetExport::find($this->exportId);
        if (! $export || $export->status === ServiceSheetExport::STATUS_DONE) {
            return;
        }

        $export->update(['status' => ServiceSheetExport::STATUS_PROCESSING]);

        try {
            $branding = AppSetting::allAsMap();
            $from = $export->from_date->copy()->startOfDay();
            $to   = $export->to_date->copy()->endOfDay();

            // Eventos del cliente cuyo momento efectivo (ocurrencia o creación) cae en el rango.
            $events = Event::where('client_id', $export->client_id)
                ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$from])
                ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$to])
                ->orderByRaw('COALESCE(occurred_at, created_at)')
                ->get();

            $dir = 'service-sheets';
            Storage::disk('local')->makeDirectory($dir);
            $relPath = $dir . '/export-' . $export->id . '-' . Str::random(8) . '.zip';
            $absPath = Storage::disk('local')->path($relPath);

            $zip = new \ZipArchive();
            if ($zip->open($absPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el archivo ZIP.');
            }

            $used = [];
            foreach ($events as $event) {
                $pdf = $renderer->renderPdf($event, $branding);
                $name = $this->uniqueName($event->folio ?: ('evento-' . $event->id), $used);
                $zip->addFromString($name, $pdf);
            }

            // Si no hubo eventos, incluye una nota para que el ZIP no quede vacío.
            if ($events->isEmpty()) {
                $zip->addFromString('SIN-EVENTOS.txt', 'No se encontraron eventos para este cliente en el rango seleccionado.');
            }

            $zip->close();

            $export->update([
                'status'      => ServiceSheetExport::STATUS_DONE,
                'file_path'   => $relPath,
                'event_count' => $events->count(),
                'error'       => null,
            ]);

            Notification::createFor($export->requested_by, 'service_sheets_ready', [
                'export_id' => $export->id,
                'count'     => $events->count(),
                'client'    => optional($export->client)->name,
                'from'      => $export->from_date->format('Y-m-d'),
                'to'        => $export->to_date->format('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => ServiceSheetExport::STATUS_FAILED,
                'error'  => Str::limit($e->getMessage(), 500),
            ]);
            Notification::createFor($export->requested_by, 'service_sheets_failed', [
                'export_id' => $export->id,
                'client'    => optional($export->client)->name,
            ]);
        }
    }

    /** Nombre de archivo único dentro del ZIP (evita choques por folios repetidos). */
    private function uniqueName(string $folio, array &$used): string
    {
        $base = preg_replace('/[^\w\-]+/u', '-', $folio);
        $name = $base . '.pdf';
        $i = 2;
        while (isset($used[$name])) {
            $name = $base . '-' . $i . '.pdf';
            $i++;
        }
        $used[$name] = true;
        return $name;
    }
}
