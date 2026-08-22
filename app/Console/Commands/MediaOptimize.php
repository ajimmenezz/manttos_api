<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Optimiza las imágenes que YA están en disco.
 *
 * La optimización al subir sólo arregla lo que entra de ahora en adelante; esto es para
 * el rezago. Se puede correr las veces que haga falta: una imagen ya optimizada se detecta
 * porque el resultado no pesaría menos, y se deja intacta.
 *
 *   php artisan media:optimize --dry-run     # cuánto se ahorraría, sin tocar nada
 *   php artisan media:optimize               # de verdad
 */
class MediaOptimize extends Command
{
    protected $signature = 'media:optimize
        {--dry-run : Sólo reporta cuánto se ahorraría, sin modificar archivos}
        {--path= : Carpeta relativa al disco público (por defecto, todas las de imágenes)}
        {--max=2000 : Lado mayor en píxeles}
        {--quality=82 : Calidad JPEG/WebP}';

    protected $description = 'Reduce el peso de las imágenes ya almacenadas, sin cambiar de formato';

    /** Carpetas del disco público donde vive lo que suben los usuarios. */
    private const DIRS = ['maintenance-media', 'contract-files'];

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry-run');
        $max     = max(200, (int) $this->option('max'));
        $quality = max(40, min(100, (int) $this->option('quality')));

        $dirs = $this->option('path') ? [(string) $this->option('path')] : self::DIRS;

        $files = [];

        foreach ($dirs as $dir) {
            $base = storage_path('app/public/' . trim($dir, '/'));
            if (! is_dir($base)) continue;

            foreach (File::allFiles($base) as $f) {
                if (in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $files[] = $f->getPathname();
                }
            }
        }

        if (! $files) { $this->warn('No encontré imágenes que revisar.'); return self::SUCCESS; }

        // Los PLANOS no se tratan como fotos: se leen con zoom, así que van con su propio
        // perfil (más holgado). Se identifican por la URL que guarda `floor_plans`.
        $planFiles = \Illuminate\Support\Facades\DB::table('floor_plans')
            ->whereNotNull('image_url')
            ->pluck('image_url')
            ->map(fn ($u) => basename(parse_url((string) $u, PHP_URL_PATH)))
            ->filter()
            ->flip();

        $this->info(($dry ? 'Simulando' : 'Optimizando') . ' ' . count($files) . " imágenes…");
        $this->line("  fotos: lado máx {$max}px, calidad {$quality} · planos: " . implode('px, calidad ', ImageOptimizer::profile('plan')) . " (" . count($planFiles) . " detectados)");

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $before = 0; $after = 0; $touched = 0; $skipped = 0;

        foreach ($files as $path) {
            $size    = filesize($path) ?: 0;
            $before += $size;

            // En simulación se trabaja sobre una copia: así el ahorro que se reporta es el
            // real y no una estimación, sin arriesgar el archivo bueno.
            $target = $dry ? $path . '.dry' : $path;
            if ($dry && ! @copy($path, $target)) { $after += $size; $bar->advance(); continue; }

            $esPlano = $planFiles->has(basename($path));
            [$pMax, $pQ] = $esPlano ? ImageOptimizer::profile('plan') : [$max, $quality];

            $r = ImageOptimizer::optimize($target, $pMax, $pQ);

            if ($dry) {
                $after += is_file($target) ? filesize($target) : $size;
                @unlink($target);
            } else {
                $after += $r['after'];
            }

            $r['ok'] ? $touched++ : $skipped++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $saved = max(0, $before - $after);
        $pct   = $before > 0 ? round($saved / $before * 100) : 0;

        $this->line('  antes:    ' . ImageOptimizer::human($before));
        $this->line('  después:  ' . ImageOptimizer::human($after));
        $this->line("  ahorro:   " . ImageOptimizer::human($saved) . " ({$pct}%)");
        $this->line("  tocadas:  {$touched} · sin cambio: {$skipped}");

        if ($dry) {
            $this->newLine();
            $this->comment('Simulación: no se modificó ningún archivo. Corre sin --dry-run para aplicarlo.');
        }

        return self::SUCCESS;
    }
}
