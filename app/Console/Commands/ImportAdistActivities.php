<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importa TAREAS de un mantenimiento de ADIST3 (conexión `pruebas`) como ACTIVIDADES en un
 * mantenimiento nuestro, LLENANDO los campos reales del formulario:
 *   - descripción (campo text)  ← Description de la tarea
 *   - imágenes (campos image)   ← S3Attachment de las NOTAS (se descargan y resuben)
 * Empareja el dispositivo por el `device` del additional_data contra el DID normalizado.
 * performed_at = ExecutionDate. Dry-run POR DEFECTO. Idempotente (updateOrCreate por source_ref).
 *
 *   php artisan activities:import-adist 10001311 --maintenance=5 --user=23 --type=PREVENTIVO
 *   php artisan activities:import-adist 10001311 --maintenance=5 --user=23 --type=PREVENTIVO --commit
 */
class ImportAdistActivities extends Command
{
    protected $signature = 'activities:import-adist {request : IdRequest en ADIST}
        {--maintenance= : id del mantenimiento NUESTRO destino}
        {--user= : user_id al que se atribuyen las actividades}
        {--type=PREVENTIVO : NameTaskType a importar}
        {--map= : CSV de reconciliación (task_id → device_id) para forzar el device}
        {--no-images : no descargar/subir imágenes}
        {--commit : escribir de verdad (sin esto es dry-run)}';

    protected $description = 'Importa tareas de ADIST3 como actividades (device por DID, descripción + imágenes, preserva fecha)';

    private function nz(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        $s = preg_replace('/[^A-Z0-9]/', '', $s);
        return preg_replace_callback('/[0-9]+/', fn ($m) => (string) intval($m[0]), $s);
    }

    public function handle(): int
    {
        $request = $this->argument('request');
        $mId     = (int) $this->option('maintenance');
        $userId  = (int) $this->option('user');
        $type    = strtoupper($this->option('type'));
        $commit  = (bool) $this->option('commit');
        $doImg   = ! $this->option('no-images');
        $map     = $this->loadMap($this->option('map'));   // [task_id => device_id]

        $maintenance = Maintenance::find($mId);
        if (! $maintenance) { $this->error("No existe el mantenimiento #{$mId}."); return self::FAILURE; }

        $activityType = Catalog::where('type', Catalog::TYPE_ACTIVITY_TYPE)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($type)])->first();
        if (! $activityType) { $this->error("No hay tipo de actividad que empate con «{$type}»."); return self::FAILURE; }

        // Campos del formulario (para llenar descripción e imágenes).
        $fields = DB::table('activity_type_fields')
            ->where('activity_type_id', $activityType->id)->where('system_id', $maintenance->catalog_id)
            ->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $descKey = optional($fields->firstWhere('field_type', 'text'))->field_key;
        $imgKeys = $fields->where('field_type', 'image')->pluck('field_key')->values()->all();

        // Mapa DID normalizado → device_id.
        $devs = DB::table('devices as d')->join('directories as dir', 'dir.id', '=', 'd.directory_id')
            ->where('dir.site_id', $maintenance->site_id)->where('dir.catalog_id', $maintenance->catalog_id)
            ->whereNull('d.archived_at')->get(['d.id', DB::raw("d.custom_fields->>'did' as did")]);
        $byDid = [];
        foreach ($devs as $d) { if ($d->did) $byDid[$this->nz($d->did)][] = $d->id; }

        $tasks = DB::connection('pruebas')->select("
            SELECT trb.Id, trb.Title, trb.Description, trb.ExecutionDate, trb.DateCreate,
                   ad.Value AS device, p.Nombres AS assigned
            FROM t_maintenance_tasks trb
            LEFT JOIN t_maintenance_tasks_additional_data ad ON ad.TaskId = trb.Id AND ad.Field = 'device'
            LEFT JOIN t_rh_personal p ON p.IdUsuario = trb.IdAssigned
            WHERE trb.IdRequest = ? AND trb.IdTaskType = (SELECT Id FROM cat_maintenance_tasks_type WHERE Name = ? LIMIT 1)
            ORDER BY trb.Id
        ", [$request, $type]);

        $this->info(sprintf('%s | mant #%d | tipo %s (#%d) | user %d | descKey=%s | imgKeys=%s | devices %d',
            $commit ? 'IMPORTAR' : 'DRY-RUN', $mId, $activityType->label, $activityType->id, $userId,
            $descKey ?: '(none)', implode(',', $imgKeys) ?: '(none)', count($devs)));
        $this->line(str_repeat('-', 105));

        $saved = 0; $noMatch = 0; $amb = 0; $imgTotal = 0; $rows = [];
        foreach ($tasks as $t) {
            $key = $this->nz($t->device);
            $deviceId = null; $estado = '';
            if (isset($map[$t->Id])) { $deviceId = $map[$t->Id]; $estado = "→ dev #{$deviceId} (hoja)"; }
            elseif (! $t->device) { $estado = 'SIN device'; $noMatch++; }
            elseif (! isset($byDid[$key])) { $estado = 'NO MATCH'; $noMatch++; }
            elseif (count($byDid[$key]) > 1) { $estado = 'AMBIGUO'; $amb++; }
            else { $deviceId = $byDid[$key][0]; $estado = "→ dev #{$deviceId}"; }

            $imgCount = 0;
            if ($commit && $deviceId) {
                // Descripción
                $fv = ['_origen' => [
                    'sistema' => 'adist3', 'task_id' => $t->Id, 'device_code' => $t->device,
                    'asignado' => $t->assigned, 'date_create' => $t->DateCreate,
                ]];
                if ($descKey) $fv[$descKey] = trim(strip_tags((string) $t->Description));

                // Imágenes (de las notas)
                if ($doImg && $imgKeys) {
                    $our = $this->importImages($this->taskImageUrls($t->Id));
                    $imgCount = count($our);
                    if ($our) {
                        $fv[$imgKeys[0]] = array_slice($our, 0, 1);
                        if (isset($imgKeys[1]) && count($our) > 1) $fv[$imgKeys[1]] = array_slice($our, 1);
                    }
                }
                $imgTotal += $imgCount;

                MaintenanceActivity::updateOrCreate(
                    ['source_ref' => 'adist3:' . $t->Id],
                    [
                        'maintenance_id' => $maintenance->id, 'device_id' => $deviceId,
                        'activity_type_id' => $activityType->id, 'user_id' => $userId,
                        'performed_at' => $t->ExecutionDate, 'field_values' => $fv,
                    ],
                );
                $saved++;
            }

            $rows[] = [$t->Id, mb_strimwidth((string) $t->device, 0, 13, '…'), $t->ExecutionDate,
                $estado, ($commit && $deviceId ? "img:{$imgCount}" : ''),
                mb_strimwidth(strip_tags((string) $t->Description), 0, 40, '…')];
        }

        $this->table(['TaskId', 'device', 'Ejecución', 'Estado', 'Imgs', 'Descripción'], $rows);
        $this->line(str_repeat('-', 105));
        $this->info(sprintf('Total %d | match: %d | ambiguos: %d | sin match: %d%s',
            count($tasks), count($tasks) - $noMatch - $amb, $amb, $noMatch,
            $commit ? " | GUARDADAS: {$saved} | imágenes subidas: {$imgTotal}" : ' | (dry-run — usa --commit)'));

        return self::SUCCESS;
    }

    /** Carga la hoja de reconciliación: task_id → device_id (columna device_id_ELEGIR). */
    private function loadMap(?string $path): array
    {
        if (! $path) return [];
        if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path)) $path = base_path($path);
        if (! is_file($path)) { $this->warn("No se encontró el CSV de mapa: {$path}"); return []; }

        $rows = array_map('str_getcsv', file($path));
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t")), array_shift($rows));
        $iTask = array_search('task_id', $header, true);
        $iDev  = array_search('device_id_elegir', $header, true);
        if ($iDev === false) $iDev = array_search('device_id', $header, true);
        if ($iTask === false || $iDev === false) { $this->warn('El CSV necesita columnas task_id y device_id_ELEGIR.'); return []; }

        $map = [];
        foreach ($rows as $r) {
            $tid = (int) ($r[$iTask] ?? 0);
            $did = (int) ($r[$iDev] ?? 0);
            if ($tid > 0 && $did > 0) $map[$tid] = $did;
        }
        return $map;
    }

    /** URLs de imágenes (S3) de las notas de una tarea. */
    private function taskImageUrls(int $taskId): array
    {
        $notes = DB::connection('pruebas')->select("SELECT S3Attachment FROM t_maintenance_tasks_notes WHERE IdTask = ?", [$taskId]);
        $urls = [];
        foreach ($notes as $n) {
            if (! $n->S3Attachment) continue;
            foreach (explode(',', $n->S3Attachment) as $u) { $u = trim($u); if ($u !== '') $urls[] = $u; }
        }
        return array_values(array_unique($urls));
    }

    /** Descarga cada imagen de S3 y la resube a nuestro storage; devuelve nuestras URLs. */
    private function importImages(array $s3urls): array
    {
        $out = [];
        foreach ($s3urls as $url) {
            try {
                $res = Http::timeout(30)->get($url);
                if (! $res->successful()) continue;
                $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $path = 'maintenance-media/adist-' . Str::uuid() . '.' . strtolower($ext);
                Storage::disk('public')->put($path, $res->body());
                $out[] = Storage::disk('public')->url($path);
            } catch (\Throwable $e) { /* imagen omitida */ }
        }
        return $out;
    }
}
