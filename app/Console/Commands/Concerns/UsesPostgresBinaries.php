<?php

namespace App\Console\Commands\Concerns;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Localiza y ejecuta los binarios de PostgreSQL (pg_dump / psql).
 *
 * No se puede asumir que estén en el PATH: en Windows viven bajo
 * "C:\Program Files\PostgreSQL\<versión>\bin" y en los servidores con Plesk bajo
 * /usr/lib/postgresql/<versión>/bin. Se busca en las rutas típicas y, si no
 * aparecen, se pide la carpeta con PG_BIN en el .env.
 */
trait UsesPostgresBinaries
{
    protected function pgBinary(string $name): string
    {
        $exe = $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        if ($dir = config('snapshot.pg_bin')) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($path)) return $path;

            throw new RuntimeException("No encontré {$exe} en PG_BIN ({$dir}).");
        }

        foreach ($this->candidateBinDirs() as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($path)) return $path;
        }

        // Último intento: que lo resuelva el PATH.
        $probe = Process::fromShellCommandline((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . $name);
        $probe->run();
        if ($probe->isSuccessful() && trim($probe->getOutput()) !== '') {
            return trim(strtok($probe->getOutput(), "\n"));
        }

        throw new RuntimeException(
            "No encontré «{$exe}». Instala las herramientas de PostgreSQL o define PG_BIN en el .env "
            . 'con la carpeta que las contiene.'
        );
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
        $process = new Process([$binary, ...$args], base_path(), [
            'PGPASSWORD' => $conn['password'],
            'PGCLIENTENCODING' => 'UTF8',
        ], null, $timeout);

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) $this->line('  ' . trim($buffer));
        });

        return $process;
    }
}
