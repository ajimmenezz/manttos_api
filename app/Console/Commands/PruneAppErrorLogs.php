<?php

namespace App\Console\Commands;

use App\Models\AppErrorLog;
use Illuminate\Console\Command;

/**
 * Retención de la bitácora de errores de la app: conserva solo los últimos 90 días,
 * igual que la auditoría. Programado a diario en routes/console.php.
 */
class PruneAppErrorLogs extends Command
{
    protected $signature = 'app-errors:prune {--days=90 : Días de retención}';

    protected $description = 'Elimina los errores de la app móvil más antiguos que N días (por defecto 90).';

    public function handle(): int
    {
        $days   = (int) $this->option('days') ?: 90;
        $cutoff = now()->subDays($days);

        $deleted = AppErrorLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Errores de la app podados: {$deleted} anteriores a {$cutoff->toDateString()} eliminados.");

        return self::SUCCESS;
    }
}
