<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Directory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Directory\DirectoryAnalyzerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analista de Directorio: expone el análisis de mejoras (limpieza de ubicaciones/áreas/etc.),
 * su aplicación en lote y el flujo de Excel (exportar sugerencias → editar → reimportar).
 *
 * Lectura → devices.view · Escritura (aplicar/importar) → devices.edit. La IA es opcional:
 * si el asistente está desactivado, el análisis se limita a las correcciones deterministas.
 */
class DirectoryAnalyzerController extends Controller
{
    /** Columnas del Excel (1-indexadas por letra). */
    private const COLS = [
        'A' => 'Campo', 'B' => 'Clave', 'C' => 'Valor actual',
        'D' => 'Sugerido', 'E' => 'Aplicar', 'F' => 'Motivo', 'G' => 'Ocurrencias',
    ];

    /** GET /directories/{directory}/analyzer/fields — campos de texto analizables. */
    public function fields(Request $request, Directory $directory): JsonResponse
    {
        abort_unless($request->user()->can('devices.view'), 403);

        return response()->json([
            'fields'     => $this->service()->analyzableFields($directory),
            'ai_enabled' => $this->aiEnabled(),
        ]);
    }

    /** POST /directories/{directory}/analyzer/analyze — sugerencias de mejora. */
    public function analyze(Request $request, Directory $directory): JsonResponse
    {
        abort_unless($request->user()->can('devices.view'), 403);

        $data = $request->validate([
            'fields'   => 'nullable|array',
            'fields.*' => 'string',
            'use_ai'   => 'nullable|boolean',
        ]);

        $useAi = ($data['use_ai'] ?? true) && $this->aiEnabled();
        $result = $this->service($useAi)->analyze($directory, $data['fields'] ?? [], $useAi);

        return response()->json($result);
    }

    /** POST /directories/{directory}/analyzer/apply — aplica los cambios aprobados. */
    public function apply(Request $request, Directory $directory): JsonResponse
    {
        abort_unless($request->user()->can('devices.edit'), 403);

        $data = $request->validate([
            'changes'             => 'required|array|min:1',
            'changes.*.field_key' => 'required|string',
            'changes.*.original'  => 'present|string',
            'changes.*.suggested' => 'required|string',
        ]);

        $affected = $this->service(false)->apply($directory, $data['changes']);

        return response()->json([
            'message'  => "Se actualizaron {$affected} dispositivo(s).",
            'affected' => $affected,
        ]);
    }

    /** POST /directories/{directory}/analyzer/export — Excel con las sugerencias para editar. */
    public function exportExcel(Request $request, Directory $directory): StreamedResponse
    {
        abort_unless($request->user()->can('devices.view'), 403);

        $data = $request->validate([
            'fields'   => 'nullable|array',
            'fields.*' => 'string',
            'use_ai'   => 'nullable|boolean',
        ]);

        $useAi = ($data['use_ai'] ?? true) && $this->aiEnabled();
        $result = $this->service($useAi)->analyze($directory, $data['fields'] ?? [], $useAi);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mejoras');

        foreach (self::COLS as $col => $label) {
            $sheet->setCellValue("{$col}1", $label);
        }
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');

        $r = 2;
        foreach ($result['suggestions'] as $s) {
            $sheet->setCellValueExplicit("A{$r}", $s['field_label'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", $s['field_key'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", $s['original'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$r}", $s['suggested'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("E{$r}", 'Sí');
            $sheet->setCellValueExplicit("F{$r}", $s['reason'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("G{$r}", $s['occurrences']);
            $r++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle("A1:G{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        // La columna "Clave" es técnica: la protegemos visualmente atenuándola.
        $sheet->getStyle("B2:B{$r}")->getFont()->getColor()->setRGB('999999');
        $sheet->freezePane('A2');

        $filename = 'mejoras-directorio-'.$directory->id.'-'.date('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** POST /directories/{directory}/analyzer/import — reimporta el Excel editado y aplica. */
    public function importExcel(Request $request, Directory $directory): JsonResponse
    {
        abort_unless($request->user()->can('devices.edit'), 403);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath())
            ->getActiveSheet();

        $changes = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $i = $row->getRowIndex();
            $key       = trim((string) $sheet->getCell("B{$i}")->getValue());
            $original  = (string) $sheet->getCell("C{$i}")->getValue();
            $suggested = trim((string) $sheet->getCell("D{$i}")->getValue());
            $aplicar   = mb_strtolower(trim((string) $sheet->getCell("E{$i}")->getValue()));

            if ($key === '' || $suggested === '') {
                continue;
            }
            $yes = in_array($aplicar, ['si', 'sí', 'yes', 'x', '1', 'true', 'aplicar'], true);
            if (! $yes) {
                continue;
            }
            $changes[] = ['field_key' => $key, 'original' => $original, 'suggested' => $suggested];
        }

        if (! $changes) {
            return response()->json(['message' => 'No hay filas marcadas para aplicar.', 'affected' => 0]);
        }

        $affected = $this->service(false)->apply($directory, $changes);

        return response()->json([
            'message'  => "Se aplicaron ".count($changes)." mejora(s): {$affected} dispositivo(s) actualizado(s).",
            'affected' => $affected,
        ]);
    }

    // ── Internos ────────────────────────────────────────────────────────────────

    /** Instancia el servicio, resolviendo el proveedor de IA sólo si se va a usar. */
    private function service(bool $withAi = false): DirectoryAnalyzerService
    {
        $provider = null;
        if ($withAi && $this->aiEnabled()) {
            $provider = ChatProviderFactory::make(AiSettings::resolved(), ToolRegistry::make());
        }

        return new DirectoryAnalyzerService($provider);
    }

    /** ¿El asistente de IA está configurado y usable? */
    private function aiEnabled(): bool
    {
        $r = AiSettings::resolved();

        return (bool) ($r['enabled'] ?? false)
            && ! empty($r['model'])
            && (($r['local'] ?? false) || AiSettings::hasApiKey());
    }
}
