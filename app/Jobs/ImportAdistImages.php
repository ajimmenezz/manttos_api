<?php

namespace App\Jobs;

use App\Models\MaintenanceActivity;
use App\Services\Imports\AdistImportService;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Descarga (en segundo plano) las imágenes de las tareas de ADIST3 ya importadas y las
 * mezcla en la actividad correspondiente. Al terminar, avisa al usuario que disparó la
 * importación con una notificación (bandeja + push).
 *
 * Se corre encolado para no bloquear la petición ni arriesgar timeouts cuando son muchas
 * tareas/imágenes. Idempotente por naturaleza: si se re-ejecuta, sustituye las imágenes.
 */
class ImportAdistImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @param  array<int,array{task_id:int, activity_id:int}>  $pairs
     * @param  array<int,string>                               $imgKeys
     */
    public function __construct(
        public array $pairs,
        public array $imgKeys,
        public int $userId,
        public int $maintenanceId,
    ) {
    }

    public function handle(AdistImportService $service, Notifier $notifier): void
    {
        if (! $this->imgKeys || ! $this->pairs) {
            return;
        }

        $withImages = 0;
        $totalImages = 0;

        foreach ($this->pairs as $pair) {
            $activity = MaintenanceActivity::find($pair['activity_id']);
            if (! $activity) {
                continue;
            }
            $n = $service->fillActivityImages($activity, (int) $pair['task_id'], $this->imgKeys);
            if ($n > 0) {
                $withImages++;
                $totalImages += $n;
            }
        }

        $notifier->send(
            [$this->userId],
            'import_adist',
            [
                'maintenance_id'  => $this->maintenanceId,
                'activities'      => count($this->pairs),
                'with_images'     => $withImages,
                'images'          => $totalImages,
                'url'             => "/maintenances/{$this->maintenanceId}",
            ],
            'Importación de ADIST lista',
            $totalImages > 0
                ? "Se subieron {$totalImages} imágenes en {$withImages} actividades."
                : 'Las actividades quedaron importadas (sin imágenes que subir).',
        );
    }
}
