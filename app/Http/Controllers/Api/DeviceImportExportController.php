<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Client;
use App\Models\Device;
use App\Models\Directory;
use App\Models\Site;
use App\Models\SystemField;
use App\Traits\ManagesDeviceData;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceImportExportController extends Controller
{
    use ManagesDeviceData;

    private function authorizeDirectoryAccess(Request $request, Client $client, Site $site, Directory $directory): void
    {
        abort_unless($site->client_id === $client->id, 404);
        abort_unless($directory->site_id === $site->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        // El admin-cliente solo accede a directorios de SUS clientes (antes pasaba sin verificar → fuga).
        if ($user->hasRole('admin-cliente') && $user->clientsAsAdmin()->where('clients.id', $client->id)->exists()) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        abort(403, 'No tienes acceso a este directorio.');
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(Request $request, Client $client, Site $site, Directory $directory): StreamedResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($request->user()->can('devices.export'), 403, 'Sin permiso para exportar dispositivos.');

        $fields  = $this->loadActiveFields($directory, $client->id);
        $devices = $directory->devices()->orderBy('created_at')->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dispositivos');

        // ── Fila de encabezados ──────────────────────────────────────────────
        $headers = ['ID', ...($fields->pluck('label')->toArray())];

        foreach ($headers as $i => $header) {
            $col = $i + 1;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $header);
        }

        // Estilo de encabezado: fondo azul oscuro, texto blanco, negrita
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        // ── Filas de datos ───────────────────────────────────────────────────
        foreach ($devices as $rowIdx => $device) {
            $row = $rowIdx + 2;
            $col = 1;

            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, $device->id);

            foreach ($fields as $field) {
                $value = $device->custom_fields[$field->field_key] ?? '';

                if ($field->field_type === 'boolean') {
                    $value = $value ? 'Sí' : 'No';
                } elseif ($field->field_type === 'date' && $value) {
                    // Siempre exportar fechas como texto ISO para evitar conversiones de Excel
                    try {
                        $value = Carbon::parse($value)->format('Y-m-d');
                    } catch (\Throwable) {}
                }

                $coord = Coordinate::stringFromColumnIndex($col++) . $row;
                $sheet->setCellValueExplicit(
                    $coord,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
        }

        // Ancho automático por columna (aproximado)
        foreach (range(1, count($headers)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        // Instrucción en fila vacía al final
        $notesRow = $devices->count() + 3;
        $sheet->setCellValue("A{$notesRow}", '— Deja ID vacío para agregar nuevos dispositivos. Modifica filas existentes para actualizarlos. —');
        $sheet->getStyle("A{$notesRow}")->applyFromArray([
            'font'      => ['italic' => true, 'color' => ['rgb' => '888888'], 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->mergeCells("A{$notesRow}:{$lastCol}{$notesRow}");

        $writer   = new Xlsx($spreadsheet);
        $filename = "dispositivos_{$directory->display_name}_" . now()->format('Ymd_His') . '.xlsx';
        $filename = str_replace([' ', '/', '\\', ':'], '_', $filename);

        return response()->streamDownload(
            function () use ($writer) { $writer->save('php://output'); },
            $filename,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    // ── Validate (dry-run) ────────────────────────────────────────────────────

    public function validateImport(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($request->user()->can('devices.import'), 403, 'Sin permiso para importar dispositivos.');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $fields = $this->loadActiveFields($directory);

        if ($fields->isEmpty()) {
            return response()->json(['message' => 'Este directorio no tiene plantilla de campos configurada.'], 422);
        }

        // Catálogo de opciones válidas para campos lista
        $catalogOptions = $this->loadCatalogOptions($fields, $directory);

        // ── Parsear Excel ────────────────────────────────────────────────────
        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();

        [$headerMap, $parseErrors] = $this->parseHeaders($sheet, $fields);

        $parsedRows       = [];
        $newCatalogValues = []; // field_key => { field_label, catalog_type, values[] }
        $rowErrors        = [];

        $highestRow = $sheet->getHighestRow();

        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $rowData = [];
            foreach ($headerMap as $label => $colIdx) {
                $cell            = $sheet->getCell(Coordinate::stringFromColumnIndex($colIdx) . $rowNum);
                $rowData[$label] = trim((string) ($cell->getFormattedValue() ?? ''));
            }

            // Ignorar filas completamente vacías o que parezcan notas
            $nonEmpty = array_filter($rowData, fn ($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) continue;

            $idRaw     = trim((string) ($rowData['ID'] ?? ''));

            // Fila de instrucciones/nota al final del archivo
            if (str_starts_with($idRaw, '—')) continue;

            // Solo tratar como ID de BD si el valor es numérico puro
            $id = ($idRaw !== '' && is_numeric($idRaw)) ? (int) $idRaw : null;

            $existingDevice = null;
            if ($id) {
                $existingDevice = Device::where('id', $id)->where('directory_id', $directory->id)->first();
                if (! $existingDevice) {
                    $rowErrors[] = "Fila {$rowNum}: El ID {$id} no corresponde a ningún dispositivo de este directorio.";
                    continue;
                }
            }

            [$customFields, $fieldErrors, $newValues] = $this->parseRowFields(
                $rowData, $fields, $catalogOptions, $rowNum
            );

            // Acumular valores de catálogo nuevos
            foreach ($newValues as $fieldKey => $info) {
                if (! isset($newCatalogValues[$fieldKey])) {
                    $newCatalogValues[$fieldKey] = $info;
                } else {
                    $newCatalogValues[$fieldKey]['values'] = array_unique(
                        array_merge($newCatalogValues[$fieldKey]['values'], $info['values'])
                    );
                }
            }

            if (! empty($fieldErrors)) {
                $rowErrors[] = "Fila {$rowNum}: " . implode('; ', $fieldErrors) . '.';
            }

            $parsedRows[] = [
                'row'           => $rowNum,
                'id'            => $existingDevice?->id,
                'custom_fields' => $customFields,
                'has_errors'    => ! empty($fieldErrors),
            ];
        }

        if (empty($parsedRows)) {
            return response()->json(['message' => 'El archivo no contiene filas de datos.'], 422);
        }

        // Guardar en caché 30 min para el commit posterior
        $importToken = Str::uuid()->toString();
        Cache::put("device_import_{$importToken}", [
            'directory_id' => $directory->id,
            'rows'         => $parsedRows,
        ], now()->addMinutes(30));

        $validRows = collect($parsedRows)->where('has_errors', false);

        return response()->json([
            'import_token'      => $importToken,
            'summary'           => [
                'total'   => count($parsedRows),
                'new'     => $validRows->whereNull('id')->count(),
                'update'  => $validRows->whereNotNull('id')->count(),
                'skipped' => collect($parsedRows)->where('has_errors', true)->count(),
            ],
            'new_catalog_values' => array_values($newCatalogValues),
            'errors'             => array_merge($parseErrors, $rowErrors),
        ]);
    }

    // ── Commit ────────────────────────────────────────────────────────────────

    public function import(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($request->user()->can('devices.import'), 403, 'Sin permiso para importar dispositivos.');

        $request->validate([
            'import_token'                          => 'required|string',
            'approved_catalog_values'               => 'nullable|array',
            'approved_catalog_values.*.catalog_type' => 'required|string|in:industry,site_type,system,device_type',
            'approved_catalog_values.*.label'        => 'required|string|max:100',
        ]);

        $cached = Cache::get("device_import_{$request->import_token}");

        if (! $cached || $cached['directory_id'] !== $directory->id) {
            return response()->json(['message' => 'Token de importación inválido o expirado. Vuelve a subir el archivo.'], 422);
        }

        // Crear ítems de catálogo aprobados (si el usuario tiene permiso)
        if ($request->user()->can('catalogs.create')) {
            foreach ($request->approved_catalog_values ?? [] as $item) {
                $catalog = Catalog::firstOrCreate(
                    ['type' => $item['catalog_type'], 'label' => $item['label']],
                    ['is_active' => true]
                );

                // Si es un tipo de dispositivo, asociarlo al sistema del directorio
                if ($item['catalog_type'] === 'device_type') {
                    DB::table('system_device_types')->updateOrInsert([
                        'system_catalog_id'      => $directory->catalog_id,
                        'device_type_catalog_id' => $catalog->id,
                    ]);
                }
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($cached, $directory, $request, &$created, &$updated, &$skipped) {
            foreach ($cached['rows'] as $rowData) {
                if ($rowData['has_errors']) {
                    $skipped++;
                    continue;
                }

                $customFields = $rowData['custom_fields'];
                $displayName  = $this->deriveDisplayName($customFields, $directory);
                $deviceType   = $this->deriveDeviceType($customFields);

                if ($rowData['id']) {
                    $device = Device::find($rowData['id']);
                    if ($device) {
                        $device->update([
                            'name'          => $displayName,
                            'device_type'   => $deviceType,
                            'custom_fields' => $customFields ?: null,
                        ]);
                        $this->syncFieldValues($device, $directory, $customFields);
                        $updated++;
                    }
                } else {
                    $device = $directory->devices()->create([
                        'name'          => $displayName,
                        'device_type'   => $deviceType,
                        'status'        => 'operativo',
                        'custom_fields' => $customFields ?: null,
                        'is_active'     => true,
                        'created_by'    => $request->user()->id,
                    ]);
                    $this->syncFieldValues($device, $directory, $customFields);
                    $created++;
                }
            }
        });

        Cache::forget("device_import_{$request->import_token}");

        return response()->json([
            'message' => "Importación completada: {$created} creados, {$updated} actualizados" .
                ($skipped > 0 ? ", {$skipped} omitidos por errores" : '') . '.',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function loadActiveFields(Directory $directory, ?int $clientId = null)
    {
        return SystemField::where('catalog_id', $directory->catalog_id)
            ->where(function ($q) use ($clientId) {
                $q->whereNull('client_id');
                if ($clientId) $q->orWhere('client_id', $clientId);
            })
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    private function loadCatalogOptions($fields, Directory $directory): array
    {
        $options = [];

        foreach ($fields->where('field_type', 'list') as $field) {
            if (! $field->catalog_type) continue;

            if ($field->catalog_type === 'device_type') {
                // Tipos de dispositivo filtrados por sistema (igual que el formulario)
                $system = Catalog::find($directory->catalog_id);
                if ($system) {
                    $values = $system->deviceTypes()->where('is_active', true)->pluck('label')->toArray();
                    // Si no hay tipos asignados al sistema, usa todos
                    if (empty($values)) {
                        $values = Catalog::ofType('device_type')->pluck('label')->toArray();
                    }
                    $options[$field->field_key] = $values;
                    continue;
                }
            }

            $options[$field->field_key] = Catalog::ofType($field->catalog_type)->pluck('label')->toArray();
        }

        return $options;
    }

    /** Mapea los encabezados del Excel a índices de columna (1-based). */
    private function parseHeaders($sheet, $fields): array
    {
        $headerMap   = [];
        $parseErrors = [];

        $highestCol = $sheet->getHighestColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);

        for ($colIdx = 1; $colIdx <= $highestColIndex; $colIdx++) {
            $header = trim((string) ($sheet->getCell(Coordinate::stringFromColumnIndex($colIdx) . '1')->getValue() ?? ''));
            // Primera ocurrencia gana — evita colisión con campos personalizados que se llamen "ID"
            if ($header !== '' && ! isset($headerMap[$header])) {
                $headerMap[$header] = $colIdx;
            }
        }

        if (! isset($headerMap['ID'])) {
            $parseErrors[] = 'El archivo no tiene columna "ID". Usa la plantilla generada por el sistema.';
        }

        return [$headerMap, $parseErrors];
    }

    /** Convierte una fila del Excel en custom_fields + detecta valores de catálogo nuevos. */
    private function parseRowFields(array $rowData, $fields, array $catalogOptions, int $rowNum): array
    {
        $customFields     = [];
        $errors           = [];
        $newCatalogValues = [];

        foreach ($fields as $field) {
            $rawValue = $rowData[$field->label] ?? null;

            // DID en modo patrón: se auto-computa desde otros campos tras el loop,
            // no se captura manualmente ni se valida como obligatorio.
            if ($field->field_type === 'did' && (($field->config['did_mode'] ?? 'text') === 'pattern')) {
                continue;
            }

            if ($rawValue === null || (string) $rawValue === '') {
                if ($field->is_required) {
                    $errors[] = "'{$field->label}' es obligatorio";
                }
                $customFields[$field->field_key] = null;
                continue;
            }

            $value = match ($field->field_type) {
                'boolean' => in_array(strtolower(trim((string) $rawValue)), ['sí', 'si', 'yes', 'true', '1']),
                'number'  => is_numeric($rawValue) ? (float) $rawValue : null,
                'date'    => $this->parseDate($rawValue),
                default   => trim((string) $rawValue),
            };

            // Validar valores de catálogo: primero intenta match exacto,
            // luego match insensible a mayúsculas/espacios antes de marcar como nuevo
            if ($field->field_type === 'list' && $value !== null && $value !== '') {
                $validOptions = $catalogOptions[$field->field_key] ?? [];

                if (! in_array($value, $validOptions, true)) {
                    // Buscar coincidencia normalizada (trim + lowercase)
                    $normalizedValue = mb_strtolower(trim((string) $value));
                    $canonicalMatch  = null;
                    foreach ($validOptions as $opt) {
                        if (mb_strtolower(trim((string) $opt)) === $normalizedValue) {
                            $canonicalMatch = $opt;
                            break;
                        }
                    }

                    if ($canonicalMatch !== null) {
                        // Usar la forma canónica del catálogo en lugar del valor del Excel
                        $value = $canonicalMatch;
                    } else {
                        // Sin coincidencia real → registrar como valor nuevo
                        if (! isset($newCatalogValues[$field->field_key])) {
                            $newCatalogValues[$field->field_key] = [
                                'field_label'  => $field->label,
                                'catalog_type' => $field->catalog_type,
                                'values'       => [],
                            ];
                        }
                        $newCatalogValues[$field->field_key]['values'][] = $value;
                    }
                }
            }

            $customFields[$field->field_key] = $value;
        }

        // Computa/normaliza los campos DID (patrón, número con relleno, texto) igual que el formulario.
        $customFields = $this->resolveDidCustomFields($customFields, $fields);

        return [$customFields, $errors, $newCatalogValues];
    }

    private function parseDate(mixed $rawValue): ?string
    {
        if ($rawValue === null || $rawValue === '') return null;
        try {
            return Carbon::parse((string) $rawValue)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
