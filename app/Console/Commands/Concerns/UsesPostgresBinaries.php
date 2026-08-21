<?php

namespace App\Console\Commands\Concerns;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Localiza y ejecuta los binarios de PostgreSQL (pg_dump / psql).
 *
 * No se puede asumir que estén en el PATH ni que PHP pueda mirarlos: en Windows
 * viven bajo "C:\Program Files\PostgreSQL\<versión>\bin" y en los servidores con
 * Plesk hay **open_basedir**, que impide a PHP hacer `is_file()` sobre /usr/bin.
 *
 * Por eso el orden de búsqueda es:
 *   1. PG_BIN del .env, si está.
 *   2. **Ejecutar** `pg_dump --version` a secas: open_basedir restringe el acceso a
 *      archivos, NO la ejecución de programas, así que en un servidor normal esto
 *      funciona aunque el binario esté fuera de la ruta permitida.
 *   3. Recorrer las carpetas típicas, saltando en silencio lo que open_basedir no
 *      deje inspeccionar.
 */
trait UsesPostgresBinaries
{
    /** @var array<string,string> nombre => ruta/comando ya resuelto */
    private array $resolvedBinaries = [];

    protected function pgBinary(string $name): string
    {
        if (isset($this->resolvedBinaries[$name])) return $this->resolvedBinaries[$name];

        $exe = $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        if ($dir = config('snapshot.pg_bin')) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if ($this->fileExists($path)) return $this->resolvedBinaries[$name] = $path;

            throw new RuntimeException("No encontré {$exe} en PG_BIN ({$dir}).");
        }

        // El PATH del sistema: sirve incluso cuando PHP no puede leer /usr/bin.
        if ($this->binaryRuns($name)) return $this->resolvedBinaries[$name] = $name;

        foreach ($this->candidateBinDirs() as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if ($this->fileExists($path)) return $this->resolvedBinaries[$name] = $path;
        }

        throw new RuntimeException($this->missingBinaryMessage($exe));
    }

    /**
     * `is_file()` sin morir: con open_basedir activo, mirar fuera de la ruta permitida
     * lanza un warning que Laravel convierte en excepción.
     */
    private function fileExists(string $path): bool
    {
        try {
            return @is_file($path);
        } catch (\Throwable) {
            return false;   // fuera de open_basedir: no es que no exista, es que no lo veo
        }
    }

    /** ¿El binario responde? Es la prueba que sí funciona bajo open_basedir. */
    private function binaryRuns(string $command): bool
    {
        if (! $this->canRunProcesses()) return false;

        try {
            $process = new Process([$command, '--version'], null, null, null, 15);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Plesk y otros hostings suelen desactivar proc_open por seguridad. */
    protected function canRunProcesses(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    private function missingBinaryMessage(string $exe): string
    {
        if (! $this->canRunProcesses()) {
            return "Este servidor tiene desactivada la ejecución de programas (proc_open), así que PHP no puede "
                . "invocar a {$exe}. Genera el respaldo por consola o pide que se habilite proc_open.";
        }

        $basedir = ini_get('open_basedir');

        return "No encontré «{$exe}» ni en el PATH ni en las rutas conocidas"
            . ($basedir ? " (open_basedir limita a: {$basedir})" : '')
            . '. Define PG_BIN en el .env con la carpeta que contiene pg_dump y psql.';
    }

    /** @return string[] */
    private function candidateBinDirs(): array
    {
        $dirs = [];

        foreach (glob('C:\\Program Files\\PostgreSQL\\*\\bin') ?: [] as $d) $dirs[] = $d;
        foreach (glob('/usr/lib/postgresql/*/bin') ?: [] as $d) $dirs[] = $d;
        foreach (glob('/usr/pgsql-*/bin') ?: [] as $d) $dirs[] = $d;
        $dirs[] = '/usr/bin';
        $dirs[] = '/usr/local/bin';

        // Las versiones más nuevas primero: un pg_dump viejo no puede leer un servidor nuevo.
        rsort($dirs, SORT_NATURAL);

        return $dirs;
    }

    /** Datos de conexión de la conexión pgsql activa. */
    protected function pgConnection(?string $database = null): array
    {
        $c = config('database.connections.' . config('database.default'));

        if (($c['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Los snapshots están hechos para PostgreSQL; la conexión activa no lo es.');
        }

        return [
            'host'     => $c['host'] ?? '127.0.0.1',
            'port'     => (string) ($c['port'] ?? 5432),
            'user'     => $c['username'] ?? 'postgres',
            'password' => (string) ($c['password'] ?? ''),
            'database' => $database ?: ($c['database'] ?? ''),
        ];
    }

    /**
     * Corre un binario de PostgreSQL con la contraseña por variable de entorno
     * (nunca en la línea de comandos, que queda en el historial y en `ps`).
     */
    protected function runPg(string $binary, array $args, array $conn, ?int $timeout = 3600): Process
    {
        // El directorio de trabajo se deja en null: bajo open_basedir, Symfony no
        // siempre puede resolver base_path() para el proceso hijo.
        $process = new Process([$binary, ...$args], null, [
            'PGPASSWORD' => $conn['password'],
            'PGCLIENTENCODING' => 'UTF8',
        ], null, $timeout);

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) $this->line('  ' . trim($buffer));
        });

        return $process;
    }

    /** Diagnóstico para la interfaz: ¿se puede generar el respaldo en este servidor? */
    protected function pgToolsStatus(): array
    {
        $status = ['can_run_processes' => $this->canRunProcesses(), 'open_basedir' => ini_get('open_basedir') ?: null];

        foreach (['pg_dump', 'psql'] as $binary) {
            try {
                $status[$binary] = $this->pgBinary($binary);
            } catch (\Throwable $e) {
                $status[$binary] = null;
                $status['error']  = $e->getMessage();
            }
        }

        return $status;
    }
}
