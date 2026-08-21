<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesPostgresBinaries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Restaura un ZIP de `snapshot:export` sobre esta instalación: reemplaza la base de
 * datos completa y los archivos subidos.
 *
 * ES DESTRUCTIVO: lo que haya en esta base se pierde. Por eso pide confirmación
 * salvo que se pase --force.
 *
 * Fuera de producción **sanea** el clon (sin SMTP, sin webhooks, sin integraciones
 * ni canales, sin tokens, con una contraseña conocida): un ambiente de pruebas con
 * datos de producción no debe poder mandarle correos ni webhooks a clientes reales.
 * Al importar EN producción —mudanza de servidor— no se toca nada.
 */
class SnapshotImport extends Command
{
    use UsesPostgresBinaries;

    protected $signature = 'snapshot:import
        {file : Ruta del ZIP (o su nombre dentro de storage/app/snapshots)}
        {--database= : Importar en otra base (por defecto, la de la conexión activa)}
        {--force : No preguntar}
        {--keep-secrets : No sanear (usar al mudar la instalación a otro servidor productivo)}
        {--password= : Contraseña que queda en todos los usuarios al sanear}
        {--no-media : No restaurar imágenes ni archivos}';

    protected $description = 'Restaura un snapshot completo (base de datos + archivos) generado con snapshot:export';

    public function handle(): int
    {
        try {
            $zipPath = $this->resolveFile();
            $temp    = storage_path('app/snapshot-restore-' . uniqid());

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException("No pude abrir el ZIP: {$zipPath}");
            }

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
            if ($zip->locateName('database.sql') === false) {
                throw new RuntimeException('El ZIP no trae database.sql: no parece un snapshot.');
            }

            $conn     = $this->pgConnection($this->option('database') ?: null);
            $sanitize = ! $this->option('keep-secrets') && ! app()->environment('production');

            $this->summary($manifest, $conn, $sanitize);

            if (! $this->option('force') && ! $this->confirm("Se REEMPLAZA el contenido de «{$conn['database']}». ¿Continuar?", false)) {
                $zip->close();
                $this->line('Cancelado.');

                return self::SUCCESS;
            }

            File::ensureDirectoryExists($temp);
            $zip->extractTo($temp);
            $zip->close();

            $this->info('Restaurando la base de datos…');
            $this->restoreDatabase($conn, $temp . '/database.sql');

            if (! $this->option('no-media') && is_dir($temp . '/media')) {
                $this->info('Restaurando archivos…');
                $this->restoreMedia($temp . '/media');
            }

            // Con --database, Eloquent sigue apuntando a la base de la conexión: el
            // saneo (y cualquier consulta posterior) tiene que mirar a la restaurada.
            if ($this->option('database')) {
                config(['database.connections.' . config('database.default') . '.database' => $conn['database']]);
                DB::purge();
            }

            if ($sanitize) {
                $this->info('Saneando el clon…');
                $this->sanitize();
                $this->rewriteUrls($manifest['app_url'] ?? null);
            } else {
                $this->warn('Sin sanear: este clon conserva SMTP, webhooks, integraciones y tokens del origen.');
            }

            $this->call('optimize:clear');
            $this->call('permission:cache-reset');
            // En Windows el enlace es una unión: is_link() no la reconoce y storage:link
            // reventaría por "ya existe".
            if (! file_exists(public_path('storage'))) {
                $this->call('storage:link');
            }

            File::deleteDirectory($temp);

            $this->newLine();
            $this->info('Snapshot restaurado.');
            if ($sanitize) {
                $this->line('  Todos los usuarios quedaron con la contraseña: ' . $this->password());
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveFile(): string
    {
        $file = $this->argument('file');

        foreach ([$file, storage_path('app/' . config('snapshot.path', 'snapshots') . '/' . $file)] as $candidate) {
            if (is_file($candidate)) return $candidate;
        }

        throw new RuntimeException("No encontré el archivo: {$file}");
    }

    private function summary(array $manifest, array $conn, bool $sanitize): void
    {
        $this->newLine();
        $this->line('<comment>Snapshot</comment>');
        $this->line('  origen:   ' . ($manifest['app_url'] ?? '—') . ' (' . ($manifest['environment'] ?? '—') . ')');
        $this->line('  generado: ' . ($manifest['generated_at'] ?? '—'));
        $this->line('  base:     ' . ($manifest['database'] ?? '—'));
        $this->line('  archivos: ' . ($manifest['media_files'] ?? 0));

        foreach ($manifest['counts'] ?? [] as $table => $count) {
            $this->line(sprintf('    %-24s %s', $table, number_format($count)));
        }

        $this->newLine();
        $this->line('<comment>Destino</comment>');
        $this->line('  base:     ' . $conn['database'] . ' @ ' . $conn['host'] . ':' . $conn['port']);
        $this->line('  entorno:  ' . app()->environment());
        $this->line('  saneo:    ' . ($sanitize ? 'sí (sin SMTP, webhooks, integraciones ni tokens)' : 'NO (mudanza a producción)'));
        $this->newLine();
    }

    private function restoreDatabase(array $conn, string $sqlFile): void
    {
        $psql = $this->pgBinary('psql');

        // Esquema en blanco: el volcado trae DROP de lo suyo, pero una tabla que ya no
        // existe en el origen se quedaría aquí y ensuciaría el clon.
        $reset = $this->runPg($psql, [
            '--host=' . $conn['host'], '--port=' . $conn['port'],
            '--username=' . $conn['user'], '--dbname=' . $conn['database'],
            '--quiet', '--command=DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;',
        ], $conn, 120);

        if (! $reset->isSuccessful()) {
            throw new RuntimeException('No pude limpiar el esquema: ' . trim($reset->getErrorOutput()));
        }

        $restore = $this->runPg($psql, [
            '--host=' . $conn['host'], '--port=' . $conn['port'],
            '--username=' . $conn['user'], '--dbname=' . $conn['database'],
            '--quiet',
            '--set=ON_ERROR_STOP=1',
            '--file=' . $sqlFile,
        ], $conn);

        if (! $restore->isSuccessful()) {
            throw new RuntimeException('psql falló al restaurar: ' . trim($restore->getErrorOutput()));
        }
    }

    private function restoreMedia(string $mediaDir): void
    {
        $copied = 0;

        foreach (config('snapshot.media', []) as $relative) {
            $from = $mediaDir . '/' . $relative;
            if (! is_dir($from)) continue;

            $to = storage_path($relative);
            File::ensureDirectoryExists($to);

            foreach (File::allFiles($from) as $file) {
                $target = $to . DIRECTORY_SEPARATOR . $file->getRelativePathname();
                File::ensureDirectoryExists(dirname($target));
                File::copy($file->getPathname(), $target, true);
                $copied++;
            }
        }

        $this->line("  {$copied} archivos restaurados");
    }

    /**
     * Corta toda comunicación con el mundo real y deja los usuarios accesibles.
     * Cada paso es tolerante: una instalación vieja puede no tener alguna tabla.
     */
    private function sanitize(): void
    {
        $config = config('snapshot.sanitize');

        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->whereIn('key', $config['settings_keys'])->delete();
            $this->line('  correo (SMTP) desconfigurado');
        }

        foreach ($config['disable'] as $table => $columns) {
            if (! Schema::hasTable($table)) continue;
            $available = array_filter($columns, fn ($v, $c) => Schema::hasColumn($table, $c), ARRAY_FILTER_USE_BOTH);
            if ($available) {
                DB::table($table)->update($available);
                $this->line("  {$table}: desactivado");
            }
        }

        foreach ($config['truncate'] as $table) {
            if (! Schema::hasTable($table)) continue;
            DB::table($table)->delete();
            $this->line("  {$table}: vaciado");
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->update([
                'password'              => Hash::make($this->password()),
                'must_change_password'  => false,
            ]);
            $this->line('  contraseñas unificadas');
        }
    }

    /**
     * Cambia el dominio del origen por el de esta instalación en las columnas que
     * guardan URLs de archivos. Los JSON se reescriben como texto y se vuelven a
     * castear: las fotos viven dentro de `field_values`/`custom_fields`.
     */
    private function rewriteUrls(?string $origin): void
    {
        $origin = rtrim((string) $origin, '/');
        $target = rtrim((string) config('app.url'), '/');

        if ($origin === '' || $target === '' || $origin === $target) return;

        $changed = 0;

        foreach (config('snapshot.url_columns', []) as $table => $columns) {
            if (! Schema::hasTable($table)) continue;

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) continue;

                $type = DB::selectOne(
                    'select data_type from information_schema.columns where table_name = ? and column_name = ?',
                    [$table, $column],
                )->data_type ?? 'text';

                $expr = in_array($type, ['json', 'jsonb'], true)
                    ? "\"{$column}\" = REPLACE(\"{$column}\"::text, ?, ?)::{$type}"
                    : "\"{$column}\" = REPLACE(\"{$column}\", ?, ?)";

                $changed += DB::update(
                    "update \"{$table}\" set {$expr} where \"{$column}\"::text like ?",
                    [$origin, $target, '%' . $origin . '%'],
                );
            }
        }

        if ($changed) $this->line("  URLs reapuntadas a {$target}: {$changed} registros");
    }

    private function password(): string
    {
        return $this->option('password') ?: config('snapshot.sanitize.password');
    }
}
