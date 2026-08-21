<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesPostgresBinaries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Empaqueta la instalación completa en un ZIP portable: base de datos (usuarios,
 * roles, permisos, configuración y datos) + los archivos subidos (fotos, planos,
 * logos) + un manifiesto con qué versión lo generó.
 *
 * Con ese ZIP se clona el sistema en otro servidor o en la máquina de desarrollo
 * con `snapshot:import`. NO incluye el .env: las credenciales del servidor se
 * quedan donde están.
 */
class SnapshotExport extends Command
{
    use UsesPostgresBinaries;

    protected $signature = 'snapshot:export
        {--out= : Ruta del ZIP a generar (por defecto storage/app/snapshots)}
        {--no-media : Solo la base de datos, sin imágenes ni archivos subidos}';

    protected $description = 'Exporta toda la instalación (base de datos + archivos) a un ZIP portable';

    public function handle(): int
    {
        $conn = $this->pgConnection();
        $temp = storage_path('app/snapshot-tmp-' . uniqid());
        File::ensureDirectoryExists($temp);

        try {
            $this->info("Exportando la base «{$conn['database']}»…");
            $sql = $temp . '/database.sql';
            $this->dumpDatabase($conn, $sql);
            $this->line('  base de datos: ' . $this->human(filesize($sql)));

            $media = $this->option('no-media') ? [] : $this->collectMedia();
            if ($media) {
                $this->line('  archivos: ' . count($media) . ' (' . $this->human(array_sum(array_map('filesize', array_keys($media)))) . ')');
            }

            $zipPath = $this->resolveOutputPath($conn['database']);
            File::ensureDirectoryExists(dirname($zipPath));

            $this->info('Empaquetando…');
            $this->buildZip($zipPath, $sql, $media, $conn);

            $this->newLine();
            $this->info('Snapshot listo:');
            $this->line('  ' . $zipPath);
            $this->line('  ' . $this->human(filesize($zipPath)));
            $this->newLine();
            $this->line('Para restaurarlo en otra instalación:');
            $this->line('  php artisan snapshot:import ' . basename($zipPath));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    private function dumpDatabase(array $conn, string $target): void
    {
        // --clean/--if-exists dejan el volcado idempotente; --no-owner/--no-privileges
        // permiten restaurar con otro usuario de base de datos (otro servidor).
        $process = $this->runPg($this->pgBinary('pg_dump'), [
            '--host=' . $conn['host'],
            '--port=' . $conn['port'],
            '--username=' . $conn['user'],
            '--dbname=' . $conn['database'],
            '--format=plain',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--encoding=UTF8',
            '--file=' . $target,
        ], $conn);

        if (! $process->isSuccessful() || ! is_file($target)) {
            throw new RuntimeException('pg_dump falló: ' . trim($process->getErrorOutput()));
        }
    }

    /** @return array<string,string> ruta absoluta => ruta dentro del ZIP */
    private function collectMedia(): array
    {
        $files = [];

        foreach (config('snapshot.media', []) as $relative) {
            $base = storage_path($relative);
            if (! is_dir($base)) continue;

            foreach (File::allFiles($base) as $file) {
                // Los enlaces simbólicos (storage/app/public/storage) se ignoran: se
                // recrean con `storage:link` en el destino.
                if (is_link($file->getPathname())) continue;

                $files[$file->getPathname()] = 'media/' . $relative . '/' . str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        return $files;
    }

    private function buildZip(string $zipPath, string $sql, array $media, array $conn): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No pude crear el ZIP en {$zipPath}.");
        }

        $zip->addFile($sql, 'database.sql');

        foreach ($media as $absolute => $inside) {
            $zip->addFile($absolute, $inside);
        }

        $zip->addFromString('manifest.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'app_name'     => config('app.name'),
            'app_url'      => config('app.url'),
            'environment'  => app()->environment(),
            'database'     => $conn['database'],
            'pg_version'   => $this->serverVersion(),
            'last_migration'=> $this->lastMigration(),
            'media_files'  => count($media),
            'counts'       => $this->counts(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->close();
    }

    private function resolveOutputPath(string $database): string
    {
        if ($out = $this->option('out')) {
            return str_ends_with(strtolower($out), '.zip')
                ? $out
                : rtrim($out, '/\\') . DIRECTORY_SEPARATOR . $this->defaultName($database);
        }

        return storage_path('app/' . config('snapshot.path', 'snapshots') . '/' . $this->defaultName($database));
    }

    private function defaultName(string $database): string
    {
        return sprintf('snapshot-%s-%s.zip', $database, now()->format('Ymd-His'));
    }

    /** Conteos rápidos para saber de un vistazo qué trae el ZIP. */
    private function counts(): array
    {
        $out = [];

        foreach (['users', 'clients', 'sites', 'devices', 'maintenance_activities', 'events'] as $table) {
            try {
                $out[$table] = \DB::table($table)->count();
            } catch (\Throwable) {
                // tabla ausente en instalaciones viejas: no es motivo para fallar
            }
        }

        return $out;
    }

    private function serverVersion(): ?string
    {
        try {
            return \DB::selectOne('select version()')->version ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lastMigration(): ?string
    {
        try {
            return \DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (\Throwable) {
            return null;
        }
    }

    private function human(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) return round($bytes, 1) . ' ' . $unit;
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' TB';
    }
}
