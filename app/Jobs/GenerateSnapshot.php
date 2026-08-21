<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\SnapshotExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Genera el respaldo completo (base de datos + archivos) en segundo plano y avisa al
 * solicitante en la campanita cuando está listo.
 *
 * Va por la cola porque en una instalación real esto tarda minutos: dejar la petición
 * HTTP colgada arriesga el timeout de PHP-FPM y deja al usuario mirando una rueda.
 */
class GenerateSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un respaldo grande puede tardar; media hora es margen suficiente. */
    public int $timeout = 1800;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        $export = SnapshotExport::find($this->exportId);
        if (! $export || $export->status === SnapshotExport::STATUS_DONE) return;

        $export->update(['status' => SnapshotExport::STATUS_PROCESSING]);

        try {
            $path = storage_path('app/' . config('snapshot.path', 'snapshots') . '/' . $export->file_name);

            $status = Artisan::call('snapshot:export', array_filter([
                '--out'      => $path,
                '--no-media' => $export->no_media,
            ]));

            $output = Artisan::output();

            if ($status !== 0 || ! is_file($path)) {
                throw new \RuntimeException(trim($output) ?: 'La exportación no generó el archivo.');
            }

            $export->update([
                'status' => SnapshotExport::STATUS_DONE,
                'size'   => filesize($path),
                'error'  => null,
            ]);

            Notification::createFor($export->requested_by, 'snapshot_ready', [
                'export_id' => $export->id,
                'file_name' => $export->file_name,
                'size'      => $export->size,
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => SnapshotExport::STATUS_FAILED,
                'error'  => Str::limit($e->getMessage(), 500),
            ]);

            Notification::createFor($export->requested_by, 'snapshot_failed', [
                'export_id' => $export->id,
                'error'     => Str::limit($e->getMessage(), 200),
            ]);
        }
    }
}
