<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de errores de la app móvil: ingesta desde el teléfono + consulta desde
 * la plataforma.
 *
 * La ingesta va FUERA de `auth:sanctum` a propósito: el caso que más urge capturar
 * es el que ocurre cuando la app todavía no tiene sesión (o la perdió), que es
 * justo cuando el usuario no puede reportar nada. El usuario se resuelve del token
 * si viene y si no la fila queda anónima pero con teléfono y versión, que es lo que
 * permite rastrearla. A cambio, la ruta va con `throttle` y todos los campos
 * acotados: lo peor que puede pasar es basura, no una fuga.
 */
class AppErrorLogController extends Controller
{
    /** Tope por envío: la app manda en lotes lo que acumuló sin señal. */
    private const MAX_BATCH = 50;

    /**
     * Ingesta (app móvil). Acepta un error o un lote y responde siempre 200 aunque
     * algo venga mal formado: si reportar un error fallara, la app entraría en un
     * bucle de reintentos por el propio reporte.
     */
    public function store(Request $request): JsonResponse
    {
        $items = $request->input('errors');
        if (! is_array($items)) {
            $items = [$request->all()];
        }
        $items = array_slice($items, 0, self::MAX_BATCH);

        $user = optional($request->user())->id ?? optional(auth('sanctum')->user())->id;
        $now  = now();
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $message = $this->clip($item['message'] ?? '', 500);
            if ($message === '') {
                continue;
            }
            $kind    = in_array($item['kind'] ?? '', AppErrorLog::KINDS, true) ? $item['kind'] : 'handled';
            $context = $this->clip($item['context'] ?? '', 120) ?: null;
            $status  = isset($item['http_status']) && is_numeric($item['http_status'])
                ? (int) $item['http_status'] : null;

            $rows[] = [
                'user_id'      => $user,
                'device_id'    => $this->clip($item['device_id'] ?? '', 64) ?: null,
                'device_name'  => $this->clip($item['device_name'] ?? '', 120) ?: null,
                'device_model' => $this->clip($item['device_model'] ?? '', 120) ?: null,
                'platform'     => $this->clip($item['platform'] ?? '', 16) ?: null,
                'os_version'   => $this->clip($item['os_version'] ?? '', 40) ?: null,
                'app_version'  => $this->clip($item['app_version'] ?? '', 20) ?: null,
                'build'        => $this->clip($item['build'] ?? '', 20) ?: null,
                'kind'         => $kind,
                'context'      => $context,
                'message'      => $message,
                'detail'       => $this->clip($item['detail'] ?? '', 8000) ?: null,
                'http_method'  => $this->clip($item['http_method'] ?? '', 10) ?: null,
                'http_url'     => $this->clip($item['http_url'] ?? '', 500) ?: null,
                'http_status'  => $status,
                'fingerprint'  => AppErrorLog::fingerprintFor($kind, $context, $message, $status),
                'occurred_at'  => $this->parseDate($item['occurred_at'] ?? null) ?? $now,
                'created_at'   => $now,
            ];
        }

        if ($rows) {
            AppErrorLog::insert($rows);
        }

        return response()->json(['received' => count($rows)]);
    }

    /**
     * Consulta (plataforma). Por defecto AGRUPADO por huella: cada fila es un error
     * distinto con cuántas veces pasó, a cuántos teléfonos y a cuántas personas —
     * que es como se prioriza. `?flat=1` devuelve las ocurrencias una por una.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('app-errors.view'), 403);

        $filters = $request->validate([
            'kind'        => 'nullable|string|max:16',
            'device_id'   => 'nullable|string|max:64',
            'user_id'     => 'nullable|integer',
            'app_version' => 'nullable|string|max:20',
            'fingerprint' => 'nullable|string|max:64',
            'status'      => 'nullable|in:open,resolved,all',
            'search'      => 'nullable|string|max:120',
            'date_from'   => 'nullable|date',
            'date_to'     => 'nullable|date',
            'flat'        => 'nullable|boolean',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        $base = $this->filtered($filters);

        if ($request->boolean('flat') || ! empty($filters['fingerprint'])) {
            $rows = (clone $base)
                ->with(['user:id,name,email', 'resolver:id,name'])
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 50);

            return response()->json($rows);
        }

        // Agrupado: una fila por error distinto. El último ejemplar da el detalle.
        $groups = (clone $base)
            ->select([
                'fingerprint',
                DB::raw('COUNT(*) as occurrences'),
                DB::raw('COUNT(DISTINCT device_id) as devices'),
                DB::raw('COUNT(DISTINCT user_id) as users'),
                DB::raw('MIN(occurred_at) as first_seen'),
                DB::raw('MAX(occurred_at) as last_seen'),
                DB::raw('MAX(id) as last_id'),
            ])
            ->groupBy('fingerprint')
            ->orderByDesc(DB::raw('MAX(occurred_at)'))
            ->paginate($filters['per_page'] ?? 50);

        $last = AppErrorLog::with(['user:id,name,email', 'resolver:id,name'])
            ->whereIn('id', collect($groups->items())->pluck('last_id'))
            ->get()->keyBy('id');

        $groups->getCollection()->transform(function ($g) use ($last) {
            $sample = $last->get($g->last_id);
            return [
                'fingerprint'  => $g->fingerprint,
                'occurrences'  => (int) $g->occurrences,
                'devices'      => (int) $g->devices,
                'users'        => (int) $g->users,
                'first_seen'   => $g->first_seen,
                'last_seen'    => $g->last_seen,
                'kind'         => $sample?->kind,
                'context'      => $sample?->context,
                'message'      => $sample?->message,
                'app_version'  => $sample?->app_version,
                'http_status'  => $sample?->http_status,
                'resolved_at'  => $sample?->resolved_at,
                'resolved_by'  => $sample?->resolver?->name,
                'last_user'    => $sample?->user?->name,
                'last_device'  => $sample?->device_name ?: $sample?->device_model,
                'last_id'      => (int) $g->last_id,
            ];
        });

        return response()->json($groups);
    }

    /** Detalle de una ocurrencia (stack completo, teléfono, versión). */
    public function show(Request $request, AppErrorLog $appError): JsonResponse
    {
        abort_unless($request->user()->can('app-errors.view'), 403);

        return response()->json([
            'error' => $appError->load(['user:id,name,email', 'resolver:id,name']),
        ]);
    }

    /**
     * Cierra (o reabre) TODO el grupo: si el mismo error le pegó a diez ingenieros,
     * atenderlo es una sola acción, no diez.
     */
    public function resolve(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('app-errors.resolve'), 403);

        $data = $request->validate([
            'fingerprint' => 'required|string|max:64',
            'note'        => 'nullable|string|max:1000',
            'reopen'      => 'nullable|boolean',
        ]);

        $reopen = (bool) ($data['reopen'] ?? false);

        $affected = AppErrorLog::where('fingerprint', $data['fingerprint'])->update($reopen ? [
            'resolved_at'     => null,
            'resolved_by'     => null,
            'resolution_note' => null,
        ] : [
            'resolved_at'     => now(),
            'resolved_by'     => $request->user()->id,
            'resolution_note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'message'  => $reopen ? 'Error reabierto.' : 'Error marcado como resuelto.',
            'affected' => $affected,
        ]);
    }

    /** Catálogos para los filtros (sólo lo presente en el periodo consultado). */
    public function filters(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('app-errors.view'), 403);

        return response()->json([
            'devices'  => AppErrorLog::selectRaw('device_id, MAX(COALESCE(device_name, device_model)) as label')
                ->whereNotNull('device_id')->groupBy('device_id')->orderBy('label')->limit(200)->get(),
            'users'    => AppErrorLog::whereNotNull('user_id')->with('user:id,name')
                ->select('user_id')->distinct()->limit(200)->get()
                ->map(fn ($r) => ['id' => $r->user_id, 'name' => optional($r->user)->name])
                ->filter(fn ($r) => $r['name'] !== null)->sortBy('name')->values(),
            'versions' => AppErrorLog::whereNotNull('app_version')->distinct()
                ->orderByDesc('app_version')->limit(50)->pluck('app_version'),
            'kinds'    => AppErrorLog::KINDS,
        ]);
    }

    /** Filtros comunes a listado agrupado y plano. */
    private function filtered(array $f)
    {
        return AppErrorLog::query()
            ->when($f['kind'] ?? null,        fn ($q, $v) => $q->where('kind', $v))
            ->when($f['device_id'] ?? null,   fn ($q, $v) => $q->where('device_id', $v))
            ->when($f['user_id'] ?? null,     fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['app_version'] ?? null, fn ($q, $v) => $q->where('app_version', $v))
            ->when($f['fingerprint'] ?? null, fn ($q, $v) => $q->where('fingerprint', $v))
            ->when($f['date_from'] ?? null,   fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['date_to'] ?? null,     fn ($q, $v) => $q->where('occurred_at', '<=', $v . ' 23:59:59'))
            ->when($f['search'] ?? null,      fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('message', 'ilike', "%{$v}%")
                ->orWhere('context', 'ilike', "%{$v}%")))
            // Por defecto sólo lo abierto: la lista sirve para atender, no para archivar.
            ->when(($f['status'] ?? 'open') !== 'all', fn ($q) => ($f['status'] ?? 'open') === 'resolved'
                ? $q->whereNotNull('resolved_at')
                : $q->whereNull('resolved_at'));
    }

    private function clip($value, int $max): string
    {
        return mb_substr(trim((string) (is_scalar($value) ? $value : json_encode($value))), 0, $max);
    }

    private function parseDate($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
