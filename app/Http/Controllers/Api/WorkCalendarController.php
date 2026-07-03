<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
