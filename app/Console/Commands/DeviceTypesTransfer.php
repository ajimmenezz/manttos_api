<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Exporta / importa los TIPOS DE DISPOSITIVO (catalogs.type = 'device_type') entre
 * ambientes, con su nomenclatura. Idempotente: el import hace upsert por (type, label),
 * así que re-correrlo no duplica ni pierde lo que ya exista.
 *
 * Uso típico (prod → local):
 *   1) En PROD:  php artisan devicetypes:transfer export --file=device_types.json
 *   2) Copia el archivo a local (scp / descarga).
 *   3) En LOCAL: php artisan devicetypes:transfer import --file=device_types.json
 *
 * NO copia las relaciones tipo↔sistema (system_device_types), porque dependen de IDs que
 * difieren entre ambientes; esas se re-asignan en la UII de Sistemas.
 */
class DeviceTypesTransfer extends Command
{
    protected $signature = 'devicetypes:transfer {action : export|import} {--file=device_types.json : ruta del archivo JSON}';

    protected $description = 'Exporta/importa tipos de dispositivo (con nomenclatura) entre ambientes';

    public function handle(): int
    {
        $action = $this->argument('action');
        $file   = $this->option('file');
        // Ruta relativa → relativa al proyecto.
        if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $file)) {
            $file = base_path($file);
        }

        return match ($action) {
            'export' => $this->export($file),
            'import' => $this->import($file),
            default  => tap(self::FAILURE, fn () => $this->error("Acción inválida: usa 'export' o 'import'.")),
        };
    }

    private function export(string $file): int
    {
        $rows = DB::table('catalogs')
            ->where('type', 'device_type')
            ->orderBy('sort_order')->orderBy('label')
            ->get(['label', 'nomenclatura', 'sort_order', 'is_active'])
            ->map(fn ($r) => [
                'label'        => $r->label,
                'nomenclatura' => $r->nomenclatura,
                'sort_order'   => (int) $r->sort_order,
                'is_active'    => (bool) $r->is_active,
            ])
            ->values();

        File::put($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✔ Exportados {$rows->count()} tipos de dispositivo a: {$file}");

        return self::SUCCESS;
    }

    private function import(string $file): int
    {
        if (! File::exists($file)) {
            $this->error("No existe el archivo: {$file}");
            return self::FAILURE;
        }

        $data = json_decode(File::get($file), true);
        if (! is_array($data)) {
            $this->error('El archivo no contiene un JSON válido (se esperaba un arreglo).');
            return self::FAILURE;
        }

        $created = 0; $updated = 0; $skipped = 0;
        DB::transaction(function () use ($data, &$created, &$updated, &$skipped) {
            foreach ($data as $r) {
                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') { $skipped++; continue; }

                $payload = [
                    'nomenclatura' => $r['nomenclatura'] ?? null,
                    'sort_order'   => (int) ($r['sort_order'] ?? 0),
                    'is_active'    => (bool) ($r['is_active'] ?? true),
                    'updated_at'   => now(),
                ];

                $existing = DB::table('catalogs')
                    ->where('type', 'device_type')->where('label', $label)->first();

                if ($existing) {
                    DB::table('catalogs')->where('id', $existing->id)->update($payload);
                    $updated++;
                } else {
                    DB::table('catalogs')->insert($payload + [
                        'type'       => 'device_type',
                        'label'      => $label,
                        'created_at' => now(),
                    ]);
                    $created++;
                }
            }
        });

        $this->info("✔ Import terminado: {$created} nuevos, {$updated} actualizados" . ($skipped ? ", {$skipped} omitidos (sin label)" : '') . '.');

        return self::SUCCESS;
    }
}
