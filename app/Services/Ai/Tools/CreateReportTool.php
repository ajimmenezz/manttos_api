<?php

namespace App\Services\Ai\Tools;

use App\Models\AiReport;
use App\Models\User;
use App\Services\Ai\Report\ReportRenderer;
use App\Services\Ai\Tools\Contracts\Tool;

/**
 * Genera un REPORTE VISUAL descargable (HTML autocontenido con tablas, KPIs y
 * gráficas). La IA arma los datos con las herramientas de lectura y luego llama
 * a esta con una estructura declarativa. El HTML se guarda y el front ofrece
 * verlo/descargarlo. No es una acción sensible (no modifica datos del sistema).
 */
class CreateReportTool implements Tool
{
    public function name(): string
    {
        return 'crear_reporte';
    }

    public function description(): string
    {
        return 'Genera un reporte VISUAL descargable (HTML con tablas, indicadores KPI y gráficas de barras/líneas/pastel). '
            . 'Úsala cuando el usuario pida un reporte, resumen visual o análisis presentable. PRIMERO obtén los datos con las '
            . 'herramientas de lectura y luego arma las secciones. No inventes cifras: usa datos reales de las herramientas.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title'    => ['type' => 'string', 'description' => 'Título del reporte.'],
                'subtitle' => ['type' => 'string', 'description' => 'Subtítulo o descripción (opcional).'],
                'sections' => [
                    'type'        => 'array',
                    'description' => 'Secciones del reporte, en orden.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'type'    => ['type' => 'string', 'enum' => ['kpis', 'text', 'table', 'chart'], 'description' => 'Tipo de sección.'],
                            'heading' => ['type' => 'string', 'description' => 'Encabezado (text).'],
                            'title'   => ['type' => 'string', 'description' => 'Título de la tabla o gráfica.'],
                            'content' => ['type' => 'string', 'description' => 'Texto (para type=text).'],
                            'items'   => [
                                'type'  => 'array',
                                'description' => 'KPIs (para type=kpis).',
                                'items' => ['type' => 'object', 'properties' => [
                                    'label' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                    'hint'  => ['type' => 'string'],
                                ]],
                            ],
                            'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Encabezados de columna (para type=table).'],
                            'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'Filas de la tabla (arreglo de arreglos).'],
                            'chart'   => ['type' => 'string', 'enum' => ['bar', 'line', 'pie', 'donut'], 'description' => 'Tipo de gráfica (para type=chart).'],
                            'labels'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Etiquetas del eje X / segmentos (para type=chart).'],
                            'series'  => [
                                'type'  => 'array',
                                'description' => 'Series de datos (para type=chart). En pastel usa una sola serie.',
                                'items' => ['type' => 'object', 'properties' => [
                                    'name' => ['type' => 'string'],
                                    'data' => ['type' => 'array', 'items' => ['type' => 'number']],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['title', 'sections'],
        ];
    }

    public function mutating(): bool { return false; }
    public function confirm(): bool { return false; }

    public function handle(array $args, User $user): array
    {
        $title = trim((string) ($args['title'] ?? 'Reporte'));
        if ($title === '') {
            $title = 'Reporte';
        }

        $html = app(ReportRenderer::class)->render([
            'title'    => $title,
            'subtitle' => $args['subtitle'] ?? null,
            'sections' => $args['sections'] ?? [],
        ]);

        $report = AiReport::create([
            'user_id' => $user->id,
            'title'   => $title,
            'html'    => $html,
        ]);

        // El marcador __report__ lo recoge el Agent para avisar al front.
        return [
            'message'    => 'Reporte generado. Se le mostró al usuario un botón para verlo y descargarlo.',
            'report_id'  => $report->id,
            'title'      => $title,
            '__report__' => ['id' => $report->id, 'title' => $title],
        ];
    }
}
