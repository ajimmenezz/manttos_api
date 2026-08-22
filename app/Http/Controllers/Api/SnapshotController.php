<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSnapshot;
use App\Models\SnapshotExport;
use App\Support\SnapshotProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Respaldos portables desde la interfaz: exportar TODA la instalación (base de datos
 * + archivos) a un ZIP e importarla en otra.
 *
 * Es la misma herramienta que `snapshot:export` / `snapshot:import`: aquí sólo se
 * disparan esos comandos, para no tener dos implementaciones de algo tan delicado.
 *
 * Gateado por `snapshot.manage` (superadmin por defecto): quien tenga esto puede
 * llevarse toda la base o reemplazarla.
 */
class SnapshotController extends Controller
{
    use \App\Console\Commands\Concerns\UsesPostgresBinaries;

    private function assertAllowed(Request $request): void
    {
        abort_unless($request->user()->can('snapshot.manage'), 403, 'No autorizado para esta acción.');
    }

    private function directory(): string
    {
        $dir = storage_path('app/' . config('snapshot.path', 'snapshots'));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** Un nombre de archivo de la carpeta de respaldos, nunca una ruta. */
    private function resolve(string $name): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) === 1, 422, 'Nombre de archivo inválido.');

        $path = $this->directory() . DIRECTORY_SEPARATOR . $name;

        abort_unless(is_file($path), 404, 'El respaldo ya no está en el servidor.');

        return $path;
    }

    /** GET /snapshots — respaldos disponibles + a qué instalación se le aplicarían. */
    public function index(Request $request): JsonResponse
    {
        $this->assertAllowed($request);

        $files = collect(File::files($this->directory()))
            ->filter(fn ($f) => strtolower($f->getExtension()) === 'zip')
            ->map(fn ($f) => [
                'name'       => $f->getFilename(),
                'size'       => $f->getSize(),
                'created_at' => date('c', $f->getMTime()),
            ])
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'files'   => $files,
            'current' => $this->currentSummary(),
            'limits'  => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
            ],
            // Si el servidor no puede invocar pg_dump/psql, más vale decirlo ANTES de
            // que alguien apriete el botón (Plesk: open_basedir / proc_open).
            'tools'   => $this->pgToolsStatus() + ['php' => $this->phpBinary()],
            // Respaldos en curso o recién terminados: la pantalla los sondea para
            // avisar en cuanto el ZIP está listo.
            // Avance de la última importación: es lo que contesta «¿terminó, falló
            // o quedó a medias?» cuando alguien vuelve a entrar a la pantalla.
            'import'  => SnapshotProgress::read(),
            'jobs'    => SnapshotExport::latest('id')->limit(5)->get([
                'id', 'status', 'file_name', 'size', 'error', 'created_at', 'updated_at',
            ]),
        ]);
    }

    /** Qué hay HOY en esta instalación: es lo que se perdería al importar. */
    private function currentSummary(): array
    {
        $counts = [];

        foreach (['users', 'clients', 'sites', 'devices', 'maintenance_activities', 'events'] as $table) {
            try {
                $counts[$table] = DB::table($table)->count();
            } catch (\Throwable) {
                // tabla ausente: no impide el respaldo
            }
        }

        return [
            'database'    => config('database.connections.' . config('database.default') . '.database'),
            'environment' => app()->environment(),
            'counts'      => $counts,
        ];
    }

    /**
     * POST /snapshots — encola la generación del ZIP.
     *
     * No se genera aquí mismo: en una instalación con años de fotos esto tarda
     * minutos y la petición moriría por timeout. Se responde de inmediato y el aviso
     * llega por la campanita cuando el archivo está listo.
     */
    public function store(Request $request): JsonResponse
    {
        $this->assertAllowed($request);

        // Si el servidor no puede invocar pg_dump, mejor decirlo ahora que dejar un
        // job fallando en segundo plano.
        $tools = $this->pgToolsStatus();
        if (empty($tools['pg_dump'])) {
            return response()->json(['message' => $tools['error'] ?? 'Este servidor no puede generar respaldos.'], 422);
        }

        if ($running = SnapshotExport::whereIn('status', [SnapshotExport::STATUS_PENDING, SnapshotExport::STATUS_PROCESSING])->first()) {
            return response()->json([
                'message' => 'Ya hay un respaldo generándose. Te avisamos cuando termine.',
                'job'     => $running,
            ], 202);
        }

        $database = config('database.connections.' . config('database.default') . '.database');

        $export = SnapshotExport::create([
            'status'       => SnapshotExport::STATUS_PENDING,
            'file_name'    => sprintf('snapshot-%s-%s.zip', $database, now()->format('Ymd-His')),
            'no_media'     => $request->boolean('no_media'),
            'requested_by' => $request->user()->id,
        ]);

        GenerateSnapshot::dispatch($export->id);

        return response()->json([
            'message' => 'El respaldo se está generando. Te avisamos en la campanita cuando esté listo.',
            'job'     => $export->fresh(),
        ], 202);
    }

    /**
     * GET /snapshots/{name}/download-link — enlace temporal firmado.
     *
     * La descarga NO puede ir por XHR: el navegador esperaría a tener el ZIP entero
     * en memoria, sin barra de progreso y sin poder cancelarlo, y cada clic repetido
     * pondría al servidor a leer el archivo otra vez. Con un enlace firmado la baja
     * el navegador como cualquier descarga: progreso, reanudable y sin pasar por JS.
     */
    public function downloadLink(Request $request, string $name): JsonResponse
    {
        $this->assertAllowed($request);
        $this->resolve($name);   // valida nombre y existencia antes de firmar

        return response()->json([
            'url' => URL::temporarySignedRoute('snapshots.file', now()->addMinutes(15), [
                'name' => $name,
                'u'    => $request->user()->id,
            ]),
        ]);
    }

    /**
     * GET /snapshots/{name}/file — la descarga en sí, autorizada por la firma del
     * enlace (no lleva token: la pide el navegador, no la app). Se revalida que el
     * usuario del enlace siga teniendo el permiso.
     */
    public function file(Request $request, string $name): BinaryFileResponse
    {
        $user = \App\Models\User::find($request->integer('u'));

        abort_unless($user && $user->can('snapshot.manage'), 403, 'No autorizado para esta descarga.');

        return response()->download($this->resolve($name));
    }

    /** POST /snapshots/upload — subir un ZIP generado en otra instalación. */
    public function upload(Request $request): JsonResponse
    {
        $this->assertAllowed($request);

        $request->validate([
            'file' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
        ], [
            'file.mimetypes' => 'El archivo debe ser el ZIP generado por «Exportar datos».',
        ]);

        $uploaded = $request->file('file');
        $name     = 'subido-' . now()->format('Ymd-His') . '.zip';
        $uploaded->move($this->directory(), $name);

        return response()->json([
            'message' => 'Respaldo recibido.',
            'file'    => ['name' => $name, 'size' => filesize($this->directory() . DIRECTORY_SEPARATOR . $name)],
        ], 201);
    }

    /**
     * POST /snapshots/{name}/import — REEMPLAZA esta instalación con el respaldo.
     *
     * Exige que el usuario escriba el nombre de la base de datos destino: es el
     * último freno antes de borrar lo que hay. Y avisa que la sesión se cae, porque
     * el saneo vacía los tokens de acceso.
     */
    public function import(Request $request, string $name): JsonResponse
    {
        $this->assertAllowed($request);

        $path     = $this->resolve($name);
        $database = config('database.connections.' . config('database.default') . '.database');

        $data = $request->validate(['confirm' => 'required|string']);

        abort_unless(
            trim($data['confirm']) === $database,
            422,
            "Para confirmar la sobrescritura escribe el nombre de la base de datos: {$database}",
        );

        // Dos importaciones a la vez se pisarían el esquema entre ellas.
        $current = SnapshotProgress::read();
        if (($current['status'] ?? null) === 'running') {
            return response()->json([
                'message'      => 'Ya hay una importación en curso.',
                'progress'     => $current,
                'progress_url' => $this->progressUrl(),
            ], 202);
        }

        SnapshotProgress::clear();
        $this->spawnImport($path);

        return response()->json([
            'message'      => 'La importación arrancó. Sigue el avance aquí; al terminar tendrás que iniciar sesión de nuevo.',
            'progress_url' => $this->progressUrl(),
        ], 202);
    }

    /**
     * GET /snapshots/import-progress — avance de la importación en curso.
     *
     * Autorizado SÓLO por la firma del enlace, y **sin tocar la base de datos**: no es
     * un descuido, es la única forma de que funcione. Mientras el import corre, el
     * esquema está borrado (`DROP SCHEMA public CASCADE`), así que consultar el usuario
     * o su permiso reventaría justo en el momento en que la pantalla más necesita
     * responder. Además el saneo vacía `personal_access_tokens`, con lo que el token
     * del navegador muere a media importación. La firma se emitió cuando el usuario ya
     * había probado tener `snapshot.manage`.
     */
    public function importProgress(): JsonResponse
    {
        return response()->json(SnapshotProgress::read() ?? ['status' => 'idle']);
    }

    /** Enlace firmado para sondear el avance (2 h: un respaldo grande tarda). */
    private function progressUrl(): string
    {
        return URL::temporarySignedRoute('snapshots.import-progress', now()->addHours(2));
    }

    /**
     * Lanza `snapshot:import` como proceso SUELTO y regresa de inmediato.
     *
     * Antes corría con `Artisan::call` dentro de la petición: con un respaldo real
     * (medio giga de fotos) tarda minutos, el proxy cortaba la conexión mucho antes y
     * la pantalla se quedaba «procesando» sin forma de saber si seguía viva.
     *
     * Tampoco va por la cola, aunque el export sí: la tabla `jobs` vive en la base que
     * este mismo comando está borrando, y al restaurar reaparecerían los pendientes
     * del ORIGEN. Un proceso aparte no depende de nada que el import destruya.
     */
    private function spawnImport(string $path): void
    {
        $command = sprintf(
            '%s %s snapshot:import %s --force --progress-file=%s',
            escapeshellarg($this->phpBinary()),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($path),
            escapeshellarg(SnapshotProgress::path()),
        );

        // La salida se guarda: si el proceso muere antes de poder marcar el fallo en el
        // archivo de avance, el log es lo único que queda para saber por qué.
        $log = escapeshellarg(storage_path('logs/snapshot-import.log'));

        $detached = windows_os()
            ? sprintf('start /B "" %s >> %s 2>&1', $command, $log)
            : sprintf('nohup %s >> %s 2>&1 &', $command, $log);

        if ($handle = popen($detached, 'r')) {
            pclose($handle);
        }
    }

    /**
     * El PHP de consola.
     *
     * `PHP_BINARY` NO sirve aquí: bajo PHP-FPM apunta al binario de FPM, que no sabe
     * correr artisan. El CLI vive en el mismo `bindir`. En Plesk suele ser
     * `/opt/plesk/php/8.2/bin/php`; si el servidor lo tiene en otro lado, se fija con
     * `PHP_CLI_BINARY` en el .env.
     */
    private function phpBinary(): string
    {
        if ($configured = config('snapshot.php_binary')) {
            return $configured;
        }

        return static::detectPhpBinary();
    }

    /**
     * Encuentra el PHP de CONSOLA.
     *
     * `PHP_BINARY` no sirve tal cual: bajo PHP-FPM apunta al binario de FPM, que no sabe
     * correr artisan. `PHP_BINDIR` tampoco es de fiar por sí solo — es el bindir con el
     * que se COMPILÓ este PHP y en varios empaquetados (Laragon en Windows) apunta a una
     * ruta que no existe en la máquina. Por eso se prueban las tres rutas plausibles y se
     * verifica que el archivo esté ahí:
     *
     *   1. el bindir de compilación            → instalaciones normales
     *   2. la carpeta del binario en curso     → `artisan serve`, CLI
     *   3. ../bin junto al binario en curso    → Plesk: .../sbin/php-fpm → .../bin/php
     *
     * Último recurso: `php` del PATH. En Plesk eso puede ser el shim de phpenv, que
     * falla en entornos mínimos; si pasa, se fija `PHP_CLI_BINARY` en el `.env`.
     */
    public static function detectPhpBinary(): string
    {
        $exe = windows_os() ? 'php.exe' : 'php';

        $candidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . $exe,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exe,
            dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $exe,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return $candidate;
        }

        return 'php';
    }

    /** DELETE /snapshots/{name} */
    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->assertAllowed($request);

        File::delete($this->resolve($name));

        return response()->json(['message' => 'Respaldo eliminado.']);
    }
}
