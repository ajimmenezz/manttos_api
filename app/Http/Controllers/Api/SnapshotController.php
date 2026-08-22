<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSnapshot;
use App\Models\SnapshotExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
            'tools'   => $this->pgToolsStatus(),
            // Respaldos en curso o recién terminados: la pantalla los sondea para
            // avisar en cuanto el ZIP está listo.
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

        @set_time_limit(0);

        $status = Artisan::call('snapshot:import', [
            'file'    => $path,
            '--force' => true,
        ]);

        $output = Artisan::output();

        if ($status !== 0) {
            return response()->json(['message' => 'La importación falló. La base pudo quedar a medias.', 'output' => $output], 500);
        }

        return response()->json([
            'message' => 'Datos importados. Vuelve a iniciar sesión.',
            'output'  => $output,
        ]);
    }

    /** DELETE /snapshots/{name} */
    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->assertAllowed($request);

        File::delete($this->resolve($name));

        return response()->json(['message' => 'Respaldo eliminado.']);
    }
}
