<?php

namespace App\Jobs;

use App\Models\Event;
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
     * @param  array<int,array{task_id:int, activity_id?:int, event_id?:int}>  $pairs
     * @param  array<int,string>  $imgKeys  (solo aplica al destino 'activity')
     * @param  string  $target  'activity' | 'event'
     */
    public function __construct(
        public array $pairs,
        public array $imgKeys,
        public int $userId,
        public ?int $maintenanceId = null,
        public string $target = 'activity',
    ) {
    }

    public function handle(AdistImportService $service, Notifier $notifier): void
    {
        if (! $this->pairs || ($this->target === 'activity' && ! $this->imgKeys)) {
            return;
        }

        if ($this->target === 'event') {
            $this->handleEvents($service, $notifier);

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

    /** Descarga las imágenes de las tareas y las mezcla en `events.images` de cada evento creado. */
    private function handleEvents(AdistImportService $service, Notifier $notifier): void
    {
        $withImages = 0;
        $totalImages = 0;

        foreach ($this->pairs as $pair) {
            $event = Event::find($pair['event_id'] ?? 0);
            if (! $event) {
                continue;
            }
            $n = $service->fillEventImages($event, (int) $pair['task_id']);
            if ($n > 0) {
                $withImages++;
                $totalImages += $n;
            }
        }

        $notifier->send(
            [$this->userId],
            'import_adist',
            [
                'events'      => count($this->pairs),
                'with_images' => $withImages,
                'images'      => $totalImages,
                'url'         => '/events',
            ],
            'Importación de ADIST lista',
            $totalImages > 0
                ? "Se subieron {$totalImages} imágenes en {$withImages} eventos."
                : 'Los eventos quedaron importados (sin imágenes que subir).',
        );
    }
}
