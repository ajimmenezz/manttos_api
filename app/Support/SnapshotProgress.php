<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Avance de una importación de snapshot, en un ARCHIVO en disco.
 *
 * No puede vivir en la base de datos: `snapshot:import` hace
 * `DROP SCHEMA public CASCADE` y cualquier fila de avance se borraría a sí misma a
 * mitad del proceso —y volvería a aparecer con los datos del ORIGEN al restaurar—.
 * Tampoco puede ir en la cola (`QUEUE_CONNECTION=database`) por lo mismo: la tabla
 * `jobs` desaparece con el esquema. Por eso el import corre como proceso suelto y
 * reporta aquí, en `storage/app/snapshot-import-progress.json`, que es lo único que
 * sobrevive al reemplazo de la base.
 *
 * El archivo sobrevive también al FINAL del proceso: es lo que permite contestar
 * «¿terminó, falló o quedó a medias?» cuando alguien vuelve a la pantalla.
 */
class SnapshotProgress
{
    /**
     * Peso de cada fase en el porcentaje total.
     *
     * No son iguales a propósito: en un respaldo real (medio giga de fotos) extraer
     * el ZIP y copiar los archivos se llevan casi todo el tiempo, mientras que el
     * volcado de la base —17 MB— pasa volando. Repartir 100/6 por fase daría una
     * barra que salta de 0 a 50 y luego se congela.
     */
    public const PHASES = [
        'extraer'   => ['peso' => 45, 'label' => 'Extrayendo el respaldo'],
        'base'      => ['peso' => 20, 'label' => 'Restaurando la base de datos'],
        'archivos'  => ['peso' => 25, 'label' => 'Restaurando archivos'],
        'verificar' => ['peso' => 4,  'label' => 'Verificando la copia'],
        'sanear'    => ['peso' => 3,  'label' => 'Saneando el clon'],
        'cerrar'    => ['peso' => 3,  'label' => 'Limpiando cachés'],
    ];

    /** Sin señales de vida en este tiempo ⇒ el proceso se cayó sin poder avisar. */
    private const STALE_SECONDS = 180;

    private array $state = [];

    /** Última escritura, para no castigar el disco con 1,243 archivos. */
    private float $lastWrite = 0;

    public function __construct(private string $path)
    {
    }

    public static function path(): string
    {
        return storage_path('app/snapshot-import-progress.json');
    }

    public static function make(?string $path = null): self
    {
        return new self($path ?: self::path());
    }

    public function start(string $sourceFile, string $database): void
    {
        $this->state = [
            'status'      => 'running',
            'file'        => basename($sourceFile),
            'database'    => $database,
            'phase'       => null,
            'phase_label' => 'Preparando…',
            'done'        => 0,
            'total'       => 0,
            'completed'   => [],           // fases ya terminadas, para el porcentaje
            'message'     => null,
            'error'       => null,
            'pid'         => function_exists('getmypid') ? (getmypid() ?: null) : null,
            'started_at'  => now()->toIso8601String(),
            'updated_at'  => now()->toIso8601String(),
            'finished_at' => null,
        ];

        $this->write(true);
    }

    /** Entra a una fase. `$total` habilita el avance fino (n de N). */
    public function phase(string $key, int $total = 0): void
    {
        if (! isset(self::PHASES[$key])) return;

        if ($this->state['phase'] ?? null) {
            $this->state['completed'][] = $this->state['phase'];
        }

        $this->state['phase']       = $key;
        $this->state['phase_label'] = self::PHASES[$key]['label'];
        $this->state['done']        = 0;
        $this->state['total']       = $total;
        // Sin esto, el detalle de la fase anterior («1,243 de 1,243 archivos») se queda
        // pegado bajo el título de la siguiente y se lee como si no avanzara.
        $this->state['message']     = null;

        $this->write(true);
    }

    /** Avance dentro de la fase actual. Se escribe con throttle: es un bucle caliente. */
    public function advance(int $done, ?string $message = null): void
    {
        $this->state['done'] = $done;
        if ($message !== null) $this->state['message'] = $message;

        $this->write();
    }

    public function finish(string $message): void
    {
        if ($this->state['phase'] ?? null) $this->state['completed'][] = $this->state['phase'];

        $this->state['status']      = 'done';
        $this->state['phase']       = null;
        $this->state['phase_label'] = 'Listo';
        $this->state['message']     = $message;
        $this->state['finished_at'] = now()->toIso8601String();

        $this->write(true);
    }

    public function fail(string $error): void
    {
        $this->state['status']      = 'failed';
        $this->state['error']       = $error;
        $this->state['finished_at'] = now()->toIso8601String();

        $this->write(true);
    }

    private function write(bool $force = false): void
    {
        $now = microtime(true);

        // 4 escrituras por segundo bastan para una barra fluida y evitan miles de
        // fsync al copiar archivo por archivo.
        if (! $force && $now - $this->lastWrite < 0.25) return;

        $this->lastWrite = $now;
        $this->state['updated_at'] = now()->toIso8601String();
        $this->state['percent']    = self::percent($this->state);

        try {
            File::ensureDirectoryExists(dirname($this->path));
            File::put($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // El avance es informativo: si no se puede escribir, la importación sigue.
        }
    }

    /** Estado actual para la pantalla, o null si nunca se ha importado aquí. */
    public static function read(?string $path = null): ?array
    {
        $path = $path ?: self::path();

        if (! is_file($path)) return null;

        $state = json_decode((string) @file_get_contents($path), true);
        if (! is_array($state)) return null;

        $state['percent'] = self::percent($state);

        // Un proceso que muere de golpe (OOM, kill, reinicio de PHP) no alcanza a
        // marcar 'failed' y el estado se quedaría en 'running' para siempre. Si lleva
        // demasiado tiempo sin latir, se reporta como interrumpido: es justo la
        // pregunta de «¿quedó a medias?».
        if (($state['status'] ?? null) === 'running') {
            $silence = time() - strtotime($state['updated_at'] ?? 'now');

            if ($silence > self::STALE_SECONDS) {
                $state['status'] = 'stalled';
                $state['error']  = sprintf(
                    'El proceso lleva %d minutos sin dar señales. Probablemente se interrumpió, y la base pudo quedar a medias: vuelve a importar el mismo respaldo.',
                    intdiv($silence, 60),
                );
            }
        }

        return $state;
    }

    public static function clear(?string $path = null): void
    {
        @unlink($path ?: self::path());
    }

    private static function percent(array $state): int
    {
        if (($state['status'] ?? null) === 'done') return 100;

        $total = array_sum(array_column(self::PHASES, 'peso'));
        $acc   = 0;

        foreach ($state['completed'] ?? [] as $key) {
            $acc += self::PHASES[$key]['peso'] ?? 0;
        }

        $phase = $state['phase'] ?? null;

        if ($phase && isset(self::PHASES[$phase])) {
            $peso = self::PHASES[$phase]['peso'];
            // Sin sub-total conocido la fase aporta la mitad de su peso: la barra se
            // mueve al entrar y no finge una precisión que no hay.
            $frac = ($state['total'] ?? 0) > 0
                ? min(1, ($state['done'] ?? 0) / $state['total'])
                : 0.5;

            $acc += $peso * $frac;
        }

        return (int) max(0, min(99, round($acc / $total * 100)));
    }
}
