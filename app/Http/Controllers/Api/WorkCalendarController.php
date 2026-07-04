<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\WorkCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Calendario laboral global: días laborables de la semana, horas productivas por día
 * y días festivos. Alimenta el plan de acción. Restringido a `config.manage`.
 */
class WorkCalendarController extends Controller
{
    /** GET /work-calendar */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $cfg = WorkCalendar::config();

        return response()->json([
            'work_days'     => $cfg['work_days'],
            'hours_per_day' => $cfg['hours_per_day'],
            'holidays'      => Holiday::orderBy('date')->get(['id', 'date', 'label']),
        ]);
    }

    /** PUT /work-calendar — actualiza días laborables y horas por día. */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $data = $request->validate([
            'work_days'      => 'present|array',
            'work_days.*'    => 'integer|min:1|max:7',
            'hours_per_day'  => 'required|numeric|min:0.5|max:24',
        ]);

        WorkCalendar::saveConfig($data['work_days'], (float) $data['hours_per_day']);

        return response()->json(['message' => 'Calendario laboral actualizado.']);
    }

    /** POST /work-calendar/holidays — agrega un festivo. */
    public function storeHoliday(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $data = $request->validate([
            'date'  => 'required|date|unique:holidays,date',
            'label' => 'nullable|string|max:120',
        ]);

        $holiday = Holiday::create([
            'date'  => $data['date'],
            'label' => $data['label'] ?? null,
        ]);

        return response()->json($holiday, 201);
    }

    /** DELETE /work-calendar/holidays/{holiday} */
    public function destroyHoliday(Holiday $holiday): JsonResponse
    {
        abort_unless(request()->user()->can('config.manage'), 403);

        $holiday->delete();

        return response()->json(['message' => 'Festivo eliminado.']);
    }

    /**
     * GET /work-calendar/holidays/suggest?year=YYYY
     * Sugiere feriados de México para el año: los oficiales (API pública Nager.Date,
     * gratis y sin llave) + los comúnmente no laborables calculados localmente
     * (Jueves/Viernes Santo, 2 nov, 12 dic). NO persiste; solo propone.
     */
    public function suggestHolidays(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $year = (int) $request->query('year', (int) date('Y'));
        abort_unless($year >= 2000 && $year <= 2100, 422, 'Año fuera de rango.');

        $items       = [];   // date 'Y-m-d' => ['date','label','source']
        $sourceOnline = false;

        // 1) Oficiales desde la API pública (si hay internet de salida).
        try {
            $resp = Http::timeout(8)->acceptJson()->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/MX");
            if ($resp->ok() && is_array($resp->json())) {
                $sourceOnline = true;
                foreach ($resp->json() as $h) {
                    if (empty($h['date'])) continue;
                    $items[$h['date']] = [
                        'date'   => $h['date'],
                        'label'  => $h['localName'] ?? $h['name'] ?? 'Feriado',
                        'source' => 'oficial',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Sin internet / API caída → seguimos con los calculados localmente.
        }

        // 2) Comúnmente no laborables (no vienen en la API oficial).
        $easter  = $this->easterDate($year);
        $extras = [
            $easter->copy()->subDays(3)->toDateString() => 'Jueves Santo',
            $easter->copy()->subDays(2)->toDateString() => 'Viernes Santo',
            "{$year}-11-02"                             => 'Día de Muertos',
            "{$year}-12-12"                             => 'Día de la Virgen de Guadalupe',
        ];
        foreach ($extras as $date => $label) {
            if (! isset($items[$date])) {
                $items[$date] = ['date' => $date, 'label' => $label, 'source' => 'comun'];
            }
        }

        // 3) Marcar los que ya existen en nuestra tabla.
        $existing = Holiday::pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $out = collect($items)->values()
            ->map(fn ($it) => [...$it, 'already_added' => $existing->has($it['date'])])
            ->sortBy('date')->values();

        return response()->json([
            'year'          => $year,
            'source_online' => $sourceOnline,
            'holidays'      => $out,
        ]);
    }

    /** POST /work-calendar/holidays/bulk — agrega los festivos que aún no existan. */
    public function bulkStoreHolidays(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $data = $request->validate([
            'holidays'         => 'present|array',
            'holidays.*.date'  => 'required|date',
            'holidays.*.label' => 'nullable|string|max:120',
        ]);

        $added = 0;
        foreach ($data['holidays'] as $row) {
            $date = Carbon::parse($row['date'])->toDateString();
            $h = Holiday::firstOrCreate(['date' => $date], ['label' => $row['label'] ?? null]);
            if ($h->wasRecentlyCreated) $added++;
        }

        return response()->json([
            'message'  => "{$added} festivo(s) agregados.",
            'added'    => $added,
            'holidays' => Holiday::orderBy('date')->get(['id', 'date', 'label']),
        ]);
    }

    /** Domingo de Pascua (algoritmo de Meeus/Jones/Butcher, calendario gregoriano). */
    private function easterDate(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
