<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera una HOJA DE RECONCILIACIÓN (CSV) de las tareas de ADIST3 que NO matchearon por DID,
 * con el tipo/habitación detectados y los mejores CANDIDATOS de nuestros devices (por tipo +
 * cuarto) para que un humano confirme el device_id. Luego se importa con:
 *   php artisan activities:import-adist ... --map=<csv> --commit
 *
 *   php artisan activities:reconcile-adist 10001311 --maintenance=5 --type=PREVENTIVO --out=reconcile.csv
 */
class ReconcileAdistActivities extends Command
{
    protected $signature = 'activities:reconcile-adist {request}
        {--maintenance=} {--type=PREVENTIVO} {--out=}';

    protected $description = 'Genera hoja CSV para reconciliar a mano los devices que no matchearon';

    private function nz(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        $s = preg_replace('/[^A-Z0-9]/', '', $s);
        return preg_replace_callback('/[0-9]+/', fn ($m) => (string) intval($m[0]), $s);
    }

    /** Palabra(s) de tipo → patrón ILIKE contra tipo_dispositivo nuestro. */
    private function typePattern(string $text): ?string
    {
        $t = strtoupper($text);
        return match (true) {
            str_contains($t, 'MULTICRITERIO')                          => '%MULTI%',
            str_contains($t, 'DETECTOR DE HUMO') || str_contains($t, 'DETECTOR DE  HUMO') => '%DETECTOR%HUMO%',
            str_contains($t, 'ESTROBO')                                => '%ESTROBO%',
            str_contains($t, 'FUENTE DE PODER') || str_contains($t, 'FUENTA DE PODER')    => '%FUENTE%',
            str_contains($t, 'ANUNCIADOR')                             => '%ANUNCIADOR%',
            str_contains($t, 'FOTOBEAM') || str_contains($t, 'PHOTOBEAM')                 => '%FOTOBEAM%',
            str_contains($t, 'ESTACION MANUAL') || str_contains($t, 'ESTACIÓN MANUAL')    => '%ESTACION%',
            str_contains($t, 'MODULO') || str_contains($t, 'TARJETA') || str_contains($t, 'PANEL') => '%PANEL%',
            str_contains($t, 'BASE')                                   => '%BASE%',
            default                                                    => null,
        };
    }

    /** Extrae tokens de habitación/ubicación del texto. */
    private function rooms(string $text): array
    {
        $t = strtoupper($text);
        $rooms = [];
        if (preg_match_all('/HAB(?:ITACION|ITACIÓN)?\s*0*(\d{2,4})/', $t, $m)) $rooms = array_merge($rooms, $m[1]);
        foreach (['LOBBY', 'PASILLO', 'COCINA', 'RESTAURANT', 'REST ', 'TORRE', 'CASETA', 'BAÑO', 'SPA', 'GYM', 'ALBERCA'] as $w) {
            if (str_contains($t, $w)) $rooms[] = trim($w);
        }
        return array_values(array_unique($rooms));
    }

    public function handle(): int
    {
        $request = $this->argument('request');
        $type    = strtoupper($this->option('type'));
        $maintenance = Maintenance::find((int) $this->option('maintenance'));
        if (! $maintenance) { $this->error('Falta --maintenance válido.'); return self::FAILURE; }
        $out = $this->option('out') ?: base_path("reconcile_{$request}.csv");
        if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $out)) $out = base_path($out);

        // Mapa DID para saber cuáles YA matchean (esos NO van a la hoja).
        $devs = DB::table('devices as d')->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $maintenance->site_id)->where('dir.catalog_id', $maintenance->catalog_id)
            ->whereNull('d.archived_at')->get(['d.id', DB::raw("d.custom_fields->>'did' as did"),
                DB::raw("d.custom_fields->>'tipo_dispositivo' as tipo"),
                DB::raw("d.custom_fields->>'dir_incendio_location' as loc"),
                DB::raw("d.custom_fields->>'dir_incendio_area' as area")]);
        $byDid = [];
        foreach ($devs as $d) { if ($d->did) $byDid[$this->nz($d->did)][] = $d->id; }

        $tasks = DB::connection('pruebas')->select("
            SELECT trb.Id, trb.Title, trb.Description, trb.ExecutionDate, ad.Value AS device
            FROM t_maintenance_tasks trb
            LEFT JOIN t_maintenance_tasks_additional_data ad ON ad.TaskId = trb.Id AND ad.Field='device'
            WHERE trb.IdRequest=? AND trb.IdTaskType=(SELECT Id FROM cat_maintenance_tasks_type WHERE Name=? LIMIT 1)
            ORDER BY trb.Id", [$request, $type]);

        $fh = fopen($out, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM para Excel
        fputcsv($fh, ['task_id', 'codigo_fuente', 'tipo_detectado', 'habitacion', 'ejecucion', 'descripcion',
            'cand1_id', 'cand1', 'cand2_id', 'cand2', 'cand3_id', 'cand3', 'device_id_ELEGIR']);

        $n = 0;
        foreach ($tasks as $t) {
            $key = $this->nz($t->device);
            if (isset($byDid[$key]) && count($byDid[$key]) === 1) continue; // ya matchea, fuera
            if (MaintenanceActivity::where('source_ref', 'adist3:' . $t->Id)->exists()) continue; // ya importado

            $text = $t->Title . ' ' . strip_tags((string) $t->Description);
            $pat  = $this->typePattern($text);
            $rooms = $this->rooms($text);

            $cands = $this->candidates($maintenance, $pat, $rooms);
            $row = [$t->Id, $t->device, $pat ? trim($pat, '%') : '?', implode('/', $rooms), $t->ExecutionDate,
                mb_strimwidth(trim(strip_tags((string) $t->Description)), 0, 90, '…')];
            for ($i = 0; $i < 3; $i++) {
                $c = $cands[$i] ?? null;
                $row[] = $c->id ?? '';
                $row[] = $c ? "{$c->did} · {$c->tipo} · " . mb_strimwidth((string) $c->loc, 0, 40, '') : '';
            }
            // Pre-llenar device_id solo si hay UN candidato claro.
            $row[] = count($cands) === 1 ? $cands[0]->id : '';
            fputcsv($fh, $row);
            $n++;
        }
        fclose($fh);

        $this->info("Hoja generada: {$out}");
        $this->line("  {$n} tareas por reconciliar. Llena la columna 'device_id_ELEGIR' y luego:");
        $this->line("  php artisan activities:import-adist {$request} --maintenance={$maintenance->id} --user=<id> --map=\"{$out}\" --commit");

        return self::SUCCESS;
    }

    /** Candidatos de nuestro directorio por tipo + cuarto (rankeados). */
    private function candidates(Maintenance $m, ?string $typePattern, array $rooms): array
    {
        $base = fn () => DB::table('devices as d')->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $m->site_id)->where('dir.catalog_id', $m->catalog_id)->whereNull('d.archived_at')
            ->select('d.id', DB::raw("d.custom_fields->>'did' as did"),
                DB::raw("d.custom_fields->>'tipo_dispositivo' as tipo"),
                DB::raw("d.custom_fields->>'dir_incendio_location' as loc"),
                DB::raw("d.custom_fields->>'dir_incendio_area' as area"));

        $roomWhere = function ($q) use ($rooms) {
            $q->where(function ($w) use ($rooms) {
                foreach ($rooms as $r) {
                    $w->orWhereRaw("UPPER(d.custom_fields->>'dir_incendio_location') LIKE ?", ['%' . strtoupper($r) . '%'])
                      ->orWhereRaw("UPPER(d.custom_fields->>'dir_incendio_area') LIKE ?", ['%' . strtoupper($r) . '%']);
                }
            });
        };

        // 1) tipo + cuarto
        if ($typePattern && $rooms) {
            $r = $base()->whereRaw("UPPER(d.custom_fields->>'tipo_dispositivo') LIKE ?", [$typePattern])->where($roomWhere)->limit(3)->get();
            if ($r->count()) return $r->all();
        }
        // 2) solo cuarto
        if ($rooms) {
            $r = $base()->where($roomWhere)->limit(3)->get();
            if ($r->count()) return $r->all();
        }
        // 3) solo tipo
        if ($typePattern) {
            return $base()->whereRaw("UPPER(d.custom_fields->>'tipo_dispositivo') LIKE ?", [$typePattern])->limit(3)->get()->all();
        }
        return [];
    }
}
