<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAdistImages;
use App\Models\Maintenance;
use App\Models\User;
use App\Services\Imports\AdistImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistente de importación desde ADIST3 (sistema anterior, conexión `pruebas`) hacia un
 * mantenimiento nuestro. Superadmin-only (permiso integrations.import-external).
 *
 * Flujo: options (elegir destino) → preview (analizar, sin escribir) → commit (crear las
 * actividades; las imágenes se bajan en segundo plano con notificación).
 *
 * A prueba de fallos: si la base externa no responde, devuelve un mensaje claro sin tumbar
 * la plataforma (mismo espíritu que las integraciones).
 */
class AdistImportController extends Controller
{
    public function __construct(private AdistImportService $service)
    {
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()->can('integrations.import-external'), 403, 'No autorizado para esta acción.');
    }

    /** Datos para armar el asistente: mantenimientos destino + usuarios para atribuir. */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $maintenances = Maintenance::visible()
            ->with(['site.client', 'system'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Maintenance $m) => [
                'id'     => $m->id,
                'label'  => trim(
                    (optional(optional($m->site)->client)->name ?: 'Cliente')
                    .' · '.(optional($m->site)->name ?: 'Sitio')
                    .' · '.(optional($m->system)->label ?: 'Sistema')
                    .' (#'.$m->id.')'
                ),
                'client' => optional(optional($m->site)->client)->name,
                'site'   => optional($m->site)->name,
                'system' => optional($m->system)->label,
            ]);

        $users = User::query()
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json([
            'maintenances' => $maintenances,
            'users'        => $users,
            'default_type' => 'PREVENTIVO',
        ]);
    }

    /** Analiza un IdRequest de ADIST contra un mantenimiento destino (no escribe nada). */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'request_id'     => ['required'],
            'maintenance_id' => ['required', 'integer', 'exists:maintenances,id'],
            'type'           => ['nullable', 'string', 'max:60'],
        ]);

        $maintenance = Maintenance::findOrFail($data['maintenance_id']);
        $type = strtoupper($data['type'] ?? 'PREVENTIVO');

        try {
            return response()->json($this->service->preview($maintenance, $data['request_id'], $type));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo consultar el sistema externo (ADIST). Revisa la conexión e inténtalo de nuevo.',
            ], 502);
        }
    }

    /** Detalle completo de una tarea de ADIST (para el drawer). */
    public function task(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'task_id' => ['required', 'integer'],
        ]);

        try {
            return response()->json($this->service->taskDetail((int) $data['task_id']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo consultar el detalle en el sistema externo (ADIST).'], 502);
        }
    }

    /** Crea las actividades para el mapa confirmado y encola la descarga de imágenes. */
    public function commit(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $data = $request->validate([
            'request_id'     => ['required'],
            'maintenance_id' => ['required', 'integer', 'exists:maintenances,id'],
            'user_id'        => ['required', 'integer', 'exists:users,id'],
            'type'           => ['nullable', 'string', 'max:60'],
            'map'            => ['required', 'array', 'min:1'],
            'map.*'          => ['required', 'integer', 'exists:devices,id'],
        ]);

        $maintenance = Maintenance::findOrFail($data['maintenance_id']);
        $type = strtoupper($data['type'] ?? 'PREVENTIVO');

        // El mapa llega como { taskId: deviceId } con llaves string; normalizamos a int→int.
        $map = [];
        foreach ($data['map'] as $taskId => $deviceId) {
            $map[(int) $taskId] = (int) $deviceId;
        }

        try {
            $result = $this->service->createActivities($maintenance, $data['request_id'], $type, (int) $data['user_id'], $map);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo importar desde el sistema externo (ADIST).'], 502);
        }

        $queuedImages = false;
        if ($result['supports_images'] && $result['pairs']) {
            ImportAdistImages::dispatch($result['pairs'], $result['imgKeys'], (int) $data['user_id'], $maintenance->id);
            $queuedImages = true;
        }

        return response()->json([
            'created'        => $result['created'],
            'skipped'        => $result['skipped'],
            'queued_images'  => $queuedImages,
            'message'        => $queuedImages
                ? "Se importaron {$result['created']} actividades. Las imágenes se están descargando en segundo plano; te avisaremos al terminar."
                : "Se importaron {$result['created']} actividades.",
        ]);
    }
}
