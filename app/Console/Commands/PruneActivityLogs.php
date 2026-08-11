<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * Retención de la Auditoría: conserva solo los últimos 90 días.
 * Programado a diario en routes/console.php.
 */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity:prune {--days=90 : Días de retención}';

    protected $description = 'Elimina los registros de auditoría más antiguos que N días (por defecto 90).';

    public function handle(): int
    {
        $days   = (int) $this->option('days') ?: 90;
        $cutoff = now()->subDays($days);

        $deleted = ActivityLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Auditoría podada: {$deleted} registros anteriores a {$cutoff->toDateString()} eliminados.");

        return self::SUCCESS;
    }
}
