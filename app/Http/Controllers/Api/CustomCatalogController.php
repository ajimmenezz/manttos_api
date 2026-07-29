<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogos/listas REUTILIZABLES del usuario (para el campo "Lista personalizada").
 * `client_id` null = global; con cliente = solo ese cliente. Lecturas gateadas por
 * `catalogs.view`, escrituras por `catalogs.edit` (reusa permisos existentes → sin reseed).
 */
class CustomCatalogController extends Controller
{
    /** Acota por rol: superadmin/admin ven todo; el resto, globales + sus clientes. */
    private function scope(Request $request)
    {
        $user = $request->user();
        $q = CustomCatalog::with('client:id,name')->orderBy('name');
        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return $q;
        }
        $clientIds = collect();
        if ($user->hasRole('admin-cliente')) $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
        if ($user->hasRole('admin-sitio'))   $clientIds = $user->sitesAsAdmin()->with('client:id')->get()->pluck('client_id')->filter()->unique();
        return $q->where(fn ($w) => $w->whereNull('client_id')->orWhereIn('client_id', $clientIds));
    }

    /** Listado para la administración. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.view'), 403);

        $items = $this->scope($request)->get()->map(fn (CustomCatalog $c) => [
            'id'          => $c->id,
            'name'        => $c->name,
            'description' => $c->description,
            'client_id'   => $c->client_id,
            'client_name' => optional($c->client)->name,
            'options'     => $c->normalizedOptions(),
            'option_count' => count($c->normalizedOptions()),
            'is_active'   => $c->is_active,
        ]);

        return response()->json(['data' => $items]);
    }

    /**
     * Opciones para los editores de campo "Lista personalizada": catálogos ACTIVOS
     * = globales + (los del cliente indicado, si aplica). Solo auth (alimenta un
     * formulario operativo, como las otras lecturas de opciones de catálogo).
     */
    public function forFields(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => 'nullable|integer|exists:clients,id']);
        $clientId = $data['client_id'] ?? null;

        $items = CustomCatalog::where('is_active', true)
            ->where(fn ($w) => $w->whereNull('client_id')->when($clientId, fn ($q) => $q->orWhere('client_id', $clientId)))
            ->orderBy('name')->get()
            ->map(fn (CustomCatalog $c) => [
                'id'        => $c->id,
                'name'      => $c->name,
                'client_id' => $c->client_id,
                'options'   => $c->normalizedOptions(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.edit'), 403);
        $data = $this->validateData($request);

        $catalog = CustomCatalog::create([
            'client_id'   => $data['client_id'] ?? null,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'options'     => $this->sanitizeOptions($data['options'] ?? []),
            'is_active'   => $data['is_active'] ?? true,
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['data' => $catalog->fresh('client')], 201);
    }

    public function update(Request $request, CustomCatalog $customCatalog): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.edit'), 403);
        $data = $this->validateData($request);

        $customCatalog->update([
            'client_id'   => $data['client_id'] ?? null,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'options'     => $this->sanitizeOptions($data['options'] ?? []),
            'is_active'   => $data['is_active'] ?? $customCatalog->is_active,
        ]);

        return response()->json(['data' => $customCatalog->fresh('client')]);
    }

    public function toggleStatus(Request $request, CustomCatalog $customCatalog): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.edit'), 403);
        $customCatalog->update(['is_active' => ! $customCatalog->is_active]);

        return response()->json(['data' => $customCatalog->fresh('client')]);
    }

    public function destroy(Request $request, CustomCatalog $customCatalog): JsonResponse
    {
        abort_unless($request->user()->can('catalogs.edit'), 403);
        $customCatalog->delete();

        return response()->json(['message' => 'Catálogo eliminado.']);
    }

    /** Descarga una plantilla Excel (Etiqueta, Valor) para capturar opciones y luego importarlas. */
    public function optionsTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Opciones');
        $sheet->setCellValue('A1', 'Etiqueta');
        $sheet->setCellValue('B1', 'Valor');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');
        // Ejemplos (el usuario los reemplaza). Si el Valor se deja vacío, se usa la Etiqueta.
        $sheet->setCellValueExplicit('A2', 'Detector de humo', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B2', 'DH-01', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', 'Estación manual', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B3', 'EM-02', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'plantilla-opciones-lista.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Parsea un Excel (columnas Etiqueta / Valor) y devuelve las opciones [{label,value}]
     * para llenar el editor. Solo transforma el archivo (no toca datos). Valor vacío → Etiqueta.
     */
    public function parseOptions(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();

        // Detecta si la primera fila es encabezado ("etiqueta"/"valor").
        $firstA = mb_strtolower(trim((string) $sheet->getCell('A1')->getValue()));
        $start = in_array($firstA, ['etiqueta', 'label', 'nombre'], true) ? 2 : 1;

        $out = [];
        $seen = [];
        foreach ($sheet->getRowIterator($start) as $row) {
            $i = $row->getRowIndex();
            $label = trim((string) $sheet->getCell("A{$i}")->getValue());
            $value = trim((string) $sheet->getCell("B{$i}")->getValue());
            if ($label === '' && $value === '') continue;
            $value = $value !== '' ? $value : $label;
            $label = $label !== '' ? $label : $value;
            if (isset($seen[$value])) continue;
            $seen[$value] = true;
            $out[] = ['label' => mb_substr($label, 0, 200), 'value' => mb_substr($value, 0, 200)];
        }

        return response()->json(['options' => $out, 'count' => count($out)]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'client_id'        => 'nullable|integer|exists:clients,id',
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:255',
            'options'          => 'required|array|min:1',
            'options.*.label'  => 'nullable|string|max:200',
            'options.*.value'  => 'nullable|string|max:200',
            'is_active'        => 'nullable|boolean',
        ]);
    }

    /** Normaliza a [{label,value}] con valores únicos y ≥1 opción no vacía. */
    private function sanitizeOptions(array $options): array
    {
        $out = [];
        $seen = [];
        foreach ($options as $o) {
            $label = trim((string) ($o['label'] ?? ''));
            $value = trim((string) ($o['value'] ?? ''));
            if ($label === '' && $value === '') continue;
            $value = $value ?: $label;
            $label = $label ?: $value;
            if (isset($seen[$value])) continue;
            $seen[$value] = true;
            $out[] = ['label' => $label, 'value' => $value];
        }
        abort_if($out === [], 422, 'El catálogo necesita al menos una opción.');

        return $out;
    }
}
