<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\StorageController;
use App\Models\StorageFile;
use App\Services\Storage\GoogleDrive;
use App\Services\Storage\StorageTree;
use Illuminate\Console\Command;

/**
 * Cierra las subidas que el cliente no confirmó.
 *
 * El archivo sube directo del navegador o del teléfono a Google, y después el cliente
 * nos avisa (`complete`). Si en medio se cerró la pestaña o se perdió la señal, el
 * archivo puede estar completo en Drive y aquí seguir «pendiente», invisible. Este
 * comando lo busca en Drive por la marca que le pusimos al abrir la sesión
 * (`appProperties.mtto_file_id`) y lo da por bueno. Pasada una semana —lo que vive una
 * sesión de subida de Google— lo que no apareció se marca como fallido.
 */
class StorageReconcile extends Command
{
    protected $signature = 'storage:reconcile';

    protected $description = 'Confirma contra Google Drive las subidas que quedaron pendientes.';

    public function handle(GoogleDrive $drive): int
    {
        if (!$drive->configured()) {
            $this->line('Almacenamiento no está configurado; nada que hacer.');
            return self::SUCCESS;
        }

        $ready = $failed = 0;
        StorageFile::where('status', 'pending')->where('created_at', '<', now()->subMinutes(15))
            ->with('uploader')->orderBy('id')->chunkById(100, function ($files) use ($drive, &$ready, &$failed) {
                foreach ($files as $file) {
                    $remote = rescue(fn () => $drive->findByAppProperty('mtto_file_id', (string) $file->id), null);
                    if ($remote) {
                        StorageController::markReady($file, $remote);
                        StorageTree::log($file->uploader, 'uploaded', $file, ['size' => $file->size, 'reconciled' => true]);
                        $ready++;
                    } elseif ($file->created_at < now()->subDays(8)) {
                        $file->update(['status' => 'failed', 'upload_url' => null]);
                        $failed++;
                    }
                }
            });

        $this->info("Subidas confirmadas: {$ready}; vencidas: {$failed}.");
        return self::SUCCESS;
    }
}
