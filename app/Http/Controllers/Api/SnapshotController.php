<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    /** POST /snapshots — genera el ZIP en el servidor. */
    public function store(Request $request): JsonResponse
    {
        $this->assertAllowed($request);

        @set_time_limit(0);

        $before = collect(File::files($this->directory()))->map->getFilename()->all();

        $status = Artisan::call('snapshot:export', array_filter([
            '--no-media' => $request->boolean('no_media'),
        ]));

        $output = Artisan::output();

        if ($status !== 0) {
            return response()->json(['message' => 'No se pudo generar el respaldo.', 'output' => $output], 500);
        }

        $file = collect(File::files($this->directory()))
            ->reject(fn ($f) => in_array($f->getFilename(), $before, true))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->first();

        return response()->json([
            'message' => 'Respaldo generado.',
            'file'    => $file ? [
                'name'       => $file->getFilename(),
                'size'       => $file->getSize(),
                'created_at' => date('c', $file->getMTime()),
            ] : null,
            'output'  => $output,
        ], 201);
    }

    /** GET /snapshots/{name}/download */
    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->assertAllowed($request);

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
