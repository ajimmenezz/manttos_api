<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportExportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plantillas de exportación de los reportes-tablero.
 *
 * Cada quien guarda sus combinaciones de bloques + firma para no rearmarlas en cada
 * descarga. Son **por usuario**: nunca se listan ni se tocan las de otro, así que no hace
 * falta un permiso propio — basta con poder ver el reporte al que pertenecen.
 */
class ReportExportTemplateController extends Controller
{
    /** Permiso que ya gobierna la entrada a cada reporte. */
    private const PERMISSION = [
        'events'       => 'events.view',
        'maintenances' => 'maintenances.report',
        'personnel'    => 'reports.personnel',
    ];

    private function assertReport(Request $request, string $report): void
    {
        abort_unless(isset(self::PERMISSION[$report]), 422, 'Reporte desconocido.');
        abort_unless($request->user()->can(self::PERMISSION[$report]), 403, 'No autorizado para este reporte.');
    }

    /** Sólo las del usuario en sesión: una plantilla ajena no debería ni verse. */
    private function own(Request $request, ReportExportTemplate $template): void
    {
        abort_unless((int) $template->user_id === (int) $request->user()->id, 404);
    }

    /** GET /report-templates?report=events */
    public function index(Request $request): JsonResponse
    {
        $report = (string) $request->query('report', '');
        $this->assertReport($request, $report);

        return response()->json(
            ReportExportTemplate::where('user_id', $request->user()->id)
                ->where('report', $report)
                ->orderBy('name')
                ->get(['id', 'name', 'sections', 'signature', 'signature_align']),
        );
    }

    /** POST /report-templates */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertReport($request, $data['report']);

        // Mismo nombre = se pisa. Guardar dos veces «Mensual cliente» y acabar con dos
        // entradas iguales en la lista sería peor que sobrescribir.
        $template = ReportExportTemplate::updateOrCreate(
            ['user_id' => $request->user()->id, 'report' => $data['report'], 'name' => $data['name']],
            [
                'sections'        => $data['sections'],
                'signature'       => $data['signature'] ?? null,
                'signature_align' => $data['signature_align'] ?? null,
            ],
        );

        return response()->json(['message' => 'Plantilla guardada.', 'template' => $template], 201);
    }

    /** PUT /report-templates/{template} — «guardar los cambios en esta plantilla». */
    public function update(Request $request, ReportExportTemplate $reportExportTemplate): JsonResponse
    {
        $this->own($request, $reportExportTemplate);
        $this->assertReport($request, $reportExportTemplate->report);

        $data = $request->validate([
            'name'            => ['sometimes', 'string', 'max:120', Rule::unique('report_export_templates')
                ->where(fn ($q) => $q->where('user_id', $request->user()->id)->where('report', $reportExportTemplate->report))
                ->ignore($reportExportTemplate->id)],
            'sections'        => 'required|array',
            'sections.*'      => 'string|max:120',
            'signature'       => 'nullable|in:end,page',
            'signature_align' => 'nullable|in:left,center,right',
        ]);

        $reportExportTemplate->update($data);

        return response()->json(['message' => 'Plantilla actualizada.', 'template' => $reportExportTemplate]);
    }

    /** DELETE /report-templates/{template} */
    public function destroy(Request $request, ReportExportTemplate $reportExportTemplate): JsonResponse
    {
        $this->own($request, $reportExportTemplate);
        $reportExportTemplate->delete();

        return response()->json(['message' => 'Plantilla eliminada.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'report'          => ['required', Rule::in(array_keys(self::PERMISSION))],
            'name'            => 'required|string|max:120',
            'sections'        => 'required|array',
            'sections.*'      => 'string|max:120',
            'signature'       => 'nullable|in:end,page',
            'signature_align' => 'nullable|in:left,center,right',
        ]);
    }
}
