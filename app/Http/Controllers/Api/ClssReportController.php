<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte CLSS (`/reports/clss`): lo que reportan los paneles de incendio de Honeywell en los sitios de esta
 * plataforma — avance de pruebas y ciclos de señal (falla, alarma, supervisión…) con su acuse y restablecimiento.
 *
 * Nada se calcula aquí: el tablero CLSS lo entrega hecho por su API de lectura (proveedor `clss`). Este controlador
 * decide QUÉ SITIOS puede ver el usuario (mismo alcance por rol que el resto de los reportes) y solo pregunta por
 * esos. Alcance de lo que se integró: ANL-CLSS-2026-002 y sus decisiones (sin formularios, contrato, SLA ni
 * prioridad: CLSS no tiene esos datos).
 *
 * Permiso `reports.clss`. Las respuestas se guardan 5 minutos: CLSS se sincroniza cada 2 horas.
 */
class ClssReportController extends Controller
{
    /** Parámetros que se reenvían al tablero tal cual (el resto se ignora). */
    private const PARAMS = ['desde', 'hasta', 'periodo', 'edificio', 'panel', 'tipo', 'tecnico', 'estado', 'clase', 'pagina', 'por_pagina'];
    private const CACHE_SEG = 300;
    private const TOPE_EXCEL = 100000;

    public function __construct(private IntegrationManager $integrations) {}

    public function estado(Request $request): JsonResponse
    {
        return $this->responder($request, 'estado');
    }

    public function pruebas(Request $request): JsonResponse
    {
        return $this->responder($request, 'pruebas');
    }

    public function pruebasDetalle(Request $request): JsonResponse
    {
        return $this->responder($request, 'pruebas/detalle');
    }

    public function eventos(Request $request): JsonResponse
    {
        return $this->responder($request, 'eventos');
    }

    public function eventosDetalle(Request $request): JsonResponse
    {
        return $this->responder($request, 'eventos/detalle');
    }

    /** Excel del detalle (`reporte=pruebas|eventos`; no `tipo`, que es el filtro de tipo de dispositivo). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        abort_unless($request->user()->can('reports.clss'), 403);
        $tipo = $request->query('reporte') === 'eventos' ? 'eventos' : 'pruebas';
        app(\App\Support\ActivityLogger::class)->log('reports', 'exported', "Exportó el reporte CLSS de {$tipo} (Excel)", ['source' => 'request']);

        $columnas = $tipo === 'pruebas'
            ? ['probado' => 'Fecha de prueba', 'sitio' => 'Sitio', 'edificio' => 'Edificio', 'panel' => 'Panel',
               'direccion' => 'Dirección', 'lazo' => 'Lazo', 'tipo' => 'Tipo de dispositivo', 'etiqueta' => 'Etiqueta',
               'zona' => 'Zona', 'resultado' => 'Resultado', 'correctivas' => 'Correctivas', 'sesion' => 'Sesión de prueba',
               'tecnico' => 'Técnico (por sesión)']
            : ['inicio' => 'Inicio', 'acuse' => 'Acuse', 'restablecido' => 'Restablecimiento', 'estado' => 'Estado',
               'clase' => 'Clase', 'senal' => 'Señal', 'detalle' => 'Detalle', 'sitio' => 'Sitio', 'edificio' => 'Edificio',
               'panel' => 'Panel', 'direccion' => 'Dirección', 'lazo' => 'Lazo', 'tipo' => 'Tipo de dispositivo',
               'etiqueta' => 'Etiqueta', 'seg_acuse' => 'Segundos al acuse', 'seg_restablecimiento' => 'Segundos al restablecimiento'];
        $fechas = ['probado', 'inicio', 'acuse', 'restablecido'];

        $filas = [];
        for ($pagina = 1; count($filas) < self::TOPE_EXCEL; $pagina++) {
            $r = $this->consultar($request, "{$tipo}/detalle", ['pagina' => $pagina, 'por_pagina' => 5000]);
            if (! $r['available']) {
                return $this->noDisponible($r);
            }
            $filas = array_merge($filas, $r['data']['filas'] ?? []);
            if ($pagina * 5000 >= ($r['data']['total'] ?? 0)) {
                break;
            }
        }

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle($tipo === 'pruebas' ? 'Pruebas CLSS' : 'Señales CLSS');
        $llaves = array_keys($columnas);
        foreach (array_values($columnas) as $i => $titulo) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $titulo);
        }
        $ultima = Coordinate::stringFromColumnIndex(count($columnas));
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(22);
        $hoja->freezePane('A2');
        foreach ($filas as $n => $fila) {
            foreach ($llaves as $i => $llave) {
                $valor = $fila[$llave] ?? '';
                if (in_array($llave, $fechas, true) && $valor) {
                    $valor = \App\Support\ExecutionDate::local(\Carbon\Carbon::parse($valor))->format('Y-m-d H:i:s');
                }
                $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . ($n + 2), (string) $valor, DataType::TYPE_STRING);
            }
        }
        foreach (range(1, count($columnas)) as $c) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $escritor = new Xlsx($libro);
        $nombre = "reporte-clss-{$tipo}_" . now()->format('Ymd_His') . '.xlsx';
        return response()->streamDownload(
            function () use ($escritor) { $escritor->save('php://output'); },
            $nombre,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
             'Content-Disposition' => "attachment; filename=\"{$nombre}\""]
        );
    }

    // ── Núcleo ───────────────────────────────────────────────────────────────────────────

    private function responder(Request $request, string $operacion): JsonResponse
    {
        abort_unless($request->user()->can('reports.clss'), 403);
        $r = $this->consultar($request, $operacion);
        if (! $r['available']) {
            return $this->noDisponible($r);
        }
        return response()->json($this->conNombres($r['data']));
    }

    /** @return array{available:bool,data?:array,reason?:string,message?:string} */
    private function consultar(Request $request, string $operacion, array $extra = []): array
    {
        $sitios = $this->sitiosPedidos($request);
        if ($sitios !== null && $sitios->isEmpty()) {
            // El usuario no alcanza ningún sitio (o pidió uno que no es suyo): no se le pregunta nada al tablero.
            return ['available' => true, 'data' => ['sitios' => [], 'vacio' => true]];
        }
        $params = array_merge(array_filter($request->only(self::PARAMS), fn ($v) => $v !== null && $v !== ''), $extra);
        if ($sitios !== null) {
            $params['sitios'] = $sitios->implode(',');
        }
        ksort($params);
        $llave = 'clss:' . $operacion . ':' . md5(json_encode($params));
        if ($guardado = Cache::get($llave)) {
            return $guardado;
        }
        $r = $this->integrations->query('clss', null, $operacion, $params);
        if ($r['available'] ?? false) {
            Cache::put($llave, $r, self::CACHE_SEG);
        }
        return $r;
    }

    /**
     * Ids de sitio a pedir: los que alcanza el usuario, recortados por `site_id` si eligió uno. null = sin recorte
     * (superadmin/admin sin filtro: el tablero responde todos los sitios con datos de CLSS).
     */
    private function sitiosPedidos(Request $request): ?Collection
    {
        $visibles = $this->siteIds($request->user());
        $pedidos = collect(explode(',', (string) $request->query('site_id', '')))->filter(fn ($v) => ctype_digit(trim($v)))
            ->map(fn ($v) => (int) $v);
        if ($request->filled('client_id')) {
            $deCliente = Site::where('client_id', (int) $request->client_id)->pluck('id');
            $pedidos = $pedidos->isEmpty() ? $deCliente : $pedidos->intersect($deCliente);
            if ($pedidos->isEmpty()) {
                return collect();
            }
        }
        if ($pedidos->isEmpty()) {
            return $visibles;
        }
        return ($visibles === null ? $pedidos : $pedidos->intersect($visibles))->values();
    }

    /** Mismo alcance por rol que la API de sincronización (SyncController::siteIds). */
    private function siteIds(User $user): ?Collection
    {
        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return null;
        }
        $clientes = collect();
        if ($user->hasRole('admin-cliente')) {
            $clientes = $clientes->merge($user->clientsAsAdmin()->pluck('clients.id'));
        }
        if ($user->hasRole('ingeniero')) {
            $clientes = $clientes->merge($user->clientsAsEngineer()->pluck('clients.id'));
        }
        $ids = Site::whereIn('client_id', $clientes->unique())->pluck('id');
        if ($user->hasRole('admin-sitio')) {
            $ids = $ids->merge($user->sitesAsAdmin()->pluck('sites.id'));
        }
        if ($user->hasRole('ingeniero')) {
            $ids = $ids->merge($user->sitesAsEngineer()->pluck('sites.id'));
        }
        return $ids->unique()->values();
    }

    /** El tablero conoce el nombre del sitio como lo copió; aquí se pone el de esta plataforma y su cliente. */
    private function conNombres(array $data): array
    {
        $ids = collect($data['sitios'] ?? [])->pluck('site_id');
        if ($ids->isEmpty()) {
            return $data;
        }
        $sitios = Site::with('client:id,name')->whereIn('id', $ids)->get(['id', 'name', 'client_id'])->keyBy('id');
        $data['sitios'] = collect($data['sitios'])->map(function ($s) use ($sitios) {
            $local = $sitios->get($s['site_id']);
            return array_merge($s, $local ? ['nombre' => $local->name, 'client_id' => $local->client_id,
                'cliente' => $local->client?->name ?? $s['cliente']] : []);
        })->all();
        return $data;
    }

    private function noDisponible(array $r): JsonResponse
    {
        $razon = $r['reason'] ?? 'error';
        $mensaje = match ($razon) {
            'not_configured' => 'La conexión con CLSS no está configurada. Un superadministrador la da de alta en Integraciones.',
            'unauthorized'   => 'El tablero CLSS rechazó la llave de lectura. Hay que generar una nueva y guardarla en Integraciones.',
            default          => 'El tablero CLSS no respondió. ' . ($r['message'] ?? ''),
        };
        return response()->json(['message' => trim($mensaje), 'reason' => $razon], $razon === 'not_configured' ? 409 : 503);
    }
}
