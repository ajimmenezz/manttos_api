<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * El inventario de mantenimientos, generado desde mantenimientos.
 *
 * Existe para resolver un problema concreto: responder «¿qué hace hoy este
 * sistema?» obliga a recorrer el router, las migraciones y el esquema otra vez.
 * Es lento y, peor, se equivoca — basta que algo haya cambiado desde la última
 * revisión para trabajar sobre una idea vieja del sistema.
 *
 * La alternativa evidente —un `.md` escrito a mano— envejece a la primera
 * migración, y un inventario en el que no se confía es peor que ninguno, porque
 * hay que verificarlo igual. Por eso esto se **genera**: los hechos salen de
 * donde viven (el router y el esquema), así que no pueden mentir. Lo que un
 * volcado NO puede dar —por qué existe cada cosa, qué se descartó, qué gotcha
 * costó encontrar— sigue en `CLAUDE.md`, escrito por gente.
 *
 *     php artisan manttos:inventario            # escribe docs/INVENTARIO.md
 *     php artisan manttos:inventario --check    # falla si está desactualizado
 *
 * El `--check` es lo que lo mantiene vivo: si el inventario y el código se
 * separan, algo lo dice en voz alta en vez de esperar a que alguien lo note.
 *
 * ⚠️ **Necesita la base de datos**: el esquema sale de ella, no de las
 * migraciones. Sin conexión ABORTA en vez de escribir un inventario a medias —
 * uno incompleto commiteado es justo la clase de archivo en el que se deja de
 * confiar.
 */
class GenerarInventario extends Command
{
    protected $signature = 'manttos:inventario {--check : no escribe; falla si el archivo está desactualizado}';

    protected $description = 'Genera docs/INVENTARIO.md con el estado real de rutas, esquema y comandos';

    private const DESTINO = 'docs/INVENTARIO.md';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('No hay conexión a la base ('.$this->baseActual().').');
            $this->line('El esquema sale de la base, no de las migraciones: sin ella');
            $this->line('este inventario saldría a medias y no sirve de nada.');

            return self::FAILURE;
        }

        $contenido = $this->construir();
        $ruta = base_path(self::DESTINO);

        if ($this->option('check')) {
            $actual = File::exists($ruta) ? File::get($ruta) : '';

            // Se compara sin la fecha: si no, el inventario estaría siempre
            // «desactualizado» por el simple paso del tiempo y el check dejaría
            // de significar nada.
            if ($this->sinFecha($actual) === $this->sinFecha($contenido)) {
                $this->info('El inventario está al día.');

                return self::SUCCESS;
            }

            $this->error('El inventario está desactualizado. Corra: php artisan manttos:inventario');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($ruta));
        File::put($ruta, $contenido);

        $this->info(self::DESTINO.' generado ('.number_format(strlen($contenido)).' caracteres).');

        return self::SUCCESS;
    }

    private function sinFecha(string $texto): string
    {
        return preg_replace('/^> Generado el .*$/m', '', $texto);
    }

    private function construir(): string
    {
        $b = [];
        $b[] = '# Inventario de mantenimientos';
        $b[] = '';
        $b[] = '> **Este archivo se GENERA.** No lo edite a mano: se sobrescribe.';
        $b[] = '> `php artisan manttos:inventario`';
        $b[] = '>';
        $b[] = '> Generado el '.now()->format('Y-m-d H:i');
        $b[] = '> Contra `'.$this->baseActual().'` en `'.config('app.env').'`.';
        $b[] = '';
        $b[] = 'Los **hechos** salen del router y del esquema, así que no pueden mentir. El';
        $b[] = '**porqué** de cada cosa vive en `CLAUDE.md`, escrito por personas: un volcado';
        $b[] = 'no explica una decisión.';
        $b[] = '';
        $b[] = 'Este archivo vale lo que valga la base contra la que se generó — si se corre';
        $b[] = 'contra un ambiente atrasado, dirá lo de ese ambiente.';
        $b[] = '';

        $b[] = $this->resumen();
        $b[] = $this->rutas();
        $b[] = $this->permisos();
        $b[] = $this->esquema();
        $b[] = $this->comandos();

        return implode("\n", $b);
    }

    // ── Bloques ─────────────────────────────────────────────────────────

    private function resumen(): string
    {
        $rutas = $this->rutasApi();
        $conGuarda = $rutas->filter(fn ($r) => $this->guardasDe($r) !== '')->count();

        $filas = [
            ['Rutas de API', $rutas->count()],
            ['— con guarda declarada', $conGuarda],
            ['— sin guarda declarada', $rutas->count() - $conGuarda],
            ['Tablas (`'.$this->baseActual().'`)', count($this->tablas())],
            ['Migraciones', count(File::glob(database_path('migrations/*.php')))],
            ['Comandos propios', count($this->comandosPropios())],
            ['Modelos', count(File::glob(app_path('Models/*.php')))],
        ];

        $t = ["## 1. En números\n", '| | |', '|---|---:|'];
        foreach ($filas as [$k, $v]) {
            $t[] = sprintf('| %s | **%s** |', $k, number_format($v));
        }

        $t[] = '';
        $t[] = '> «Sin guarda declarada» incluye lo público por diseño —webhooks entrantes,';
        $t[] = '> login, páginas abiertas—: no todas son un pendiente.';

        return implode("\n", $t)."\n";
    }

    private function rutas(): string
    {
        $t = ["\n## 2. Rutas de la API\n"];
        $t[] = 'Agrupadas por el primer tramo del camino, que es lo único estable: el';
        $t[] = 'controlador puede moverse de carpeta, pero la URL que consume el front no.';
        $t[] = '';

        $grupos = [];
        foreach ($this->rutasApi() as $r) {
            $grupos[$this->moduloDe($r->uri())][] = [
                implode('|', array_diff($r->methods(), ['HEAD'])),
                $r->uri(),
                $this->guardasDe($r),
            ];
        }

        ksort($grupos);

        foreach ($grupos as $modulo => $rutas) {
            $t[] = "### {$modulo}  \n";
            $t[] = '| Método | Ruta | Guardas |';
            $t[] = '|---|---|---|';
            usort($rutas, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
            foreach ($rutas as [$metodo, $uri, $guardas]) {
                $t[] = sprintf('| `%s` | `/%s` | %s |', $metodo, $uri, $guardas ?: '—');
            }
            $t[] = '';
        }

        return implode("\n", $t)."\n";
    }

    /** El módulo se deduce del camino: `api/clientes/{id}` → `clientes`. */
    private function moduloDe(string $uri): string
    {
        $partes = array_values(array_filter(explode('/', $uri)));
        // Se salta el prefijo `api` y los tramos de versión, que no dicen nada.
        foreach ($partes as $p) {
            if (in_array($p, ['api', 'v1', 'v2', 'v3'], true)) {
                continue;
            }

            return str_starts_with($p, '{') ? 'Otros' : $p;
        }

        return 'Otros';
    }

    /**
     * Las guardas declaradas en la ruta.
     *
     * Se comparan por ALIAS y no por clase: `gatherMiddleware()` devuelve lo que
     * está escrito en la ruta (`can:ver-clientes`), y el ARGUMENTO es justo lo
     * que hace útil esta columna. Comparando solo por clase queda vacía.
     */
    private function guardasDe($ruta): string
    {
        $partes = [];

        foreach ($ruta->gatherMiddleware() as $m) {
            if (! is_string($m)) {
                continue;
            }

            $base = explode(':', $m)[0];
            $arg = str_contains($m, ':') ? explode(':', $m, 2)[1] : '';

            // `throttle` y `substitute-bindings` son ruido: están en casi todas.
            if (in_array($base, ['substituteBindings', 'bindings'], true)) {
                continue;
            }

            $etiqueta = $arg !== '' ? '`'.$base.':'.$arg.'`' : '`'.$base.'`';

            if (! in_array($etiqueta, $partes, true)) {
                $partes[] = $etiqueta;
            }
        }

        return implode(' · ', $partes);
    }

    /**
     * Los permisos, si el proyecto los guarda en la base.
     *
     * Se descubre en vez de darlo por hecho: cada proyecto los modela distinto y
     * codificar aquí el de uno rompería el generador en los demás. Si no hay
     * tabla reconocible, se dice y se apunta al `CLAUDE.md`, que es donde vive
     * la explicación.
     */
    private function permisos(): string
    {
        $t = ["\n## 3. Permisos\n"];

        $tabla = collect(['permissions', 'cat_permissions', 'panel_permission_catalog'])
            ->first(fn ($n) => Schema::hasTable($n));

        if (! $tabla) {
            $t[] = '_Este proyecto no guarda un catálogo de permisos en la base._ Si el acceso';
            $t[] = 'se controla por roles en el código o por middleware, la explicación está en';
            $t[] = '`CLAUDE.md` — y las guardas de cada ruta salen en la sección anterior.';

            return implode("\n", $t)."\n";
        }

        $filas = DB::table($tabla)->get();
        $t[] = 'De la tabla `'.$tabla.'`, que es la fuente de verdad: **no están en el código**.';
        $t[] = '';
        $t[] = '| Llave | Nombre |';
        $t[] = '|---|---|';

        foreach ($filas as $f) {
            $d = (array) $f;
            $llave = $d['name'] ?? $d['Key'] ?? $d['GrantKey'] ?? $d['key'] ?? '';
            $nombre = $d['label'] ?? $d['Label'] ?? $d['Name'] ?? $d['description'] ?? $d['Description'] ?? '';
            $t[] = sprintf('| `%s` | %s |', $llave, $this->limpiar($nombre));
        }

        return implode("\n", $t)."\n";
    }

    private function esquema(): string
    {
        $t = ["\n## 4. Esquema de datos — `".$this->baseActual()."`\n"];
        $t[] = 'Tablas reales de la base, con sus columnas. **Salen del esquema, no de las';
        $t[] = 'migraciones**: reflejan lo que hay, no lo que se pretendía.';
        $t[] = '';

        foreach ($this->tablas() as $tabla) {
            $t[] = sprintf('- **`%s`** — %s', $tabla, implode(', ', $this->columnasDe($tabla)));
        }

        return implode("\n", $t)."\n";
    }

    /** @return list<string> */
    private function columnasDe(string $tabla): array
    {
        // Los alias NO son cosmética: MySQL 8 devuelve las etiquetas de
        // information_schema en MAYÚSCULAS y leerlas en minúsculas revienta con
        // «Undefined property». PostgreSQL las devuelve en minúsculas.
        $sql = $this->esPostgres()
            ? "select column_name as nombre, data_type as tipo, is_nullable as opcional
                 from information_schema.columns
                where table_schema = 'public' and table_name = ?
                order by ordinal_position"
            : 'select column_name as nombre, data_type as tipo, is_nullable as opcional
                 from information_schema.columns
                where table_schema = database() and table_name = ?
                order by ordinal_position';

        return collect(DB::select($sql, [$tabla]))->map(function ($c) {
            $tipo = match ($c->tipo) {
                'character varying', 'varchar', 'char', 'character' => 'texto',
                'text', 'mediumtext', 'longtext' => 'texto largo',
                'timestamp without time zone', 'timestamp with time zone', 'datetime', 'timestamp', 'date' => 'fecha',
                'bigint', 'integer', 'int', 'smallint', 'tinyint' => 'núm',
                'numeric', 'decimal', 'double', 'double precision', 'float', 'real' => 'decimal',
                'boolean' => 'sí/no',
                'json', 'jsonb' => 'json',
                default => $c->tipo,
            };

            return $c->nombre.' ('.$tipo.($c->opcional === 'YES' ? ', opcional' : '').')';
        })->all();
    }

    /** @return list<string> */
    private function tablas(): array
    {
        // Fuera la infraestructura de Laravel: no es del dominio y solo hace
        // ruido en un inventario funcional.
        $infraestructura = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
            'failed_jobs', 'sessions', 'password_reset_tokens', 'personal_access_tokens'];

        $sql = $this->esPostgres()
            ? "select tablename as nombre from pg_tables where schemaname = 'public' order by 1"
            : "select table_name as nombre from information_schema.tables
                where table_schema = database() and table_type = 'BASE TABLE' order by 1";

        return collect(DB::select($sql))
            ->pluck('nombre')
            ->reject(fn ($t) => in_array($t, $infraestructura, true))
            ->values()
            ->all();
    }

    private function comandos(): string
    {
        $t = ["\n## 5. Comandos y tareas programadas\n"];
        $t[] = '| Comando | Qué hace | Cuándo corre |';
        $t[] = '|---|---|---|';

        $programados = $this->programados();

        foreach ($this->comandosPropios() as $nombre => $descripcion) {
            $t[] = sprintf('| `%s` | %s | %s |', $nombre, $this->limpiar($descripcion), $programados[$nombre] ?? 'a mano');
        }

        // Lo programado que NO es un comando propio (`queue:work`, cierres) no
        // aparecería arriba, y es justo lo que se rompe sin que nadie lo note.
        foreach (array_diff_key($programados, $this->comandosPropios()) as $nombre => $cuando) {
            $t[] = sprintf('| `%s` | _(de Laravel o de un cierre)_ | %s |', $nombre, $cuando);
        }

        $t[] = '';
        $t[] = '> Todo esto cuelga de **un solo** `schedule:run` en el cron. Si ese cron no';
        $t[] = '> está, no falla nada de forma visible: simplemente deja de correr.';

        return implode("\n", $t)."\n";
    }

    /** @return array<string, string> */
    private function comandosPropios(): array
    {
        $salida = [];

        foreach (File::glob(app_path('Console/Commands/*.php')) as $archivo) {
            $clase = 'App\\Console\\Commands\\'.basename($archivo, '.php');
            if (! class_exists($clase)) {
                continue;
            }

            $comando = app($clase);
            $salida[$comando->getName()] = $comando->getDescription();
        }

        ksort($salida);

        return $salida;
    }

    /** @return array<string, string> */
    private function programados(): array
    {
        $salida = [];

        foreach (app(Schedule::class)->events() as $evento) {
            if (preg_match("/artisan['\"]? (\S+)/", $evento->command ?? '', $m)) {
                $salida[trim($m[1], "'\"")] = '`'.$evento->expression.'`';
            }
        }

        return $salida;
    }

    private function rutasApi()
    {
        return collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/'));
    }

    private function esPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function baseActual(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    /** Las descripciones traen saltos y pipes que romperían la tabla. */
    private function limpiar(?string $texto): string
    {
        return trim(str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string) $texto)) ?: '—';
    }
}
