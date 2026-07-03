<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Calendario laboral (global) para el plan de acción: días de la semana laborables,
 * horas productivas por día y días festivos. Zona horaria America/Mexico_City.
 *
 * La configuración vive en app_settings, clave `work_calendar` (JSON):
 *   { "work_days": [1,2,3,4,5], "hours_per_day": 8 }
 * donde work_days usa ISO-8601 (1 = lunes … 7 = domingo).
 */
class WorkCalendar
{
    public const TZ = 'America/Mexico_City';

    public const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5]; // L–V
    public const DEFAULT_HOURS_PER_DAY = 8;

    /** Configuración efectiva del calendario (con valores por defecto). */
    public static function config(): array
    {
        $raw = AppSetting::allAsMap()['work_calendar'] ?? null;
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($cfg)) $cfg = [];

        $days = $cfg['work_days'] ?? self::DEFAULT_WORK_DAYS;
        $days = array_values(array_filter(array_map('intval', (array) $days), fn ($d) => $d >= 1 && $d <= 7));
        if (empty($days)) $days = self::DEFAULT_WORK_DAYS;

        $hours = (float) ($cfg['hours_per_day'] ?? self::DEFAULT_HOURS_PER_DAY);
        if ($hours <= 0) $hours = self::DEFAULT_HOURS_PER_DAY;

        return ['work_days' => $days, 'hours_per_day' => $hours];
    }

    /** Guarda la configuración (días + horas) en app_settings. */
    public static function saveConfig(array $workDays, float $hoursPerDay): void
    {
        $days = array_values(array_filter(array_map('intval', $workDays), fn ($d) => $d >= 1 && $d <= 7));
        if (empty($days)) $days = self::DEFAULT_WORK_DAYS;
        AppSetting::setValue('work_calendar', json_encode([
            'work_days'     => $days,
            'hours_per_day' => $hoursPerDay > 0 ? $hoursPerDay : self::DEFAULT_HOURS_PER_DAY,
        ]));
    }

    /** Conjunto de fechas festivas ('Y-m-d' => true). */
    public static function holidaySet(): array
    {
        return Holiday::pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();
    }

    public static function today(): Carbon
    {
        return Carbon::today(self::TZ);
    }

    /** ¿La fecha es día laborable (día de semana válido y no festivo)? */
    public static function isWorkingDay(Carbon $date, ?array $cfg = null, ?array $holidays = null): bool
    {
        $cfg      ??= self::config();
        $holidays ??= self::holidaySet();
        return in_array($date->isoWeekday(), $cfg['work_days'], true)
            && ! isset($holidays[$date->toDateString()]);
    }

    /**
     * Lista de fechas hábiles (Carbon) entre $from y $to inclusive.
     * @return Carbon[]
     */
    public static function workingDates(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) return [];
        $cfg      = self::config();
        $holidays = self::holidaySet();
        $out = [];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            if (self::isWorkingDay($day, $cfg, $holidays)) $out[] = $day->copy();
        }
        return $out;
    }

    /** Cantidad de días hábiles entre $from y $to inclusive. */
    public static function workingDaysBetween(Carbon $from, Carbon $to): int
    {
        return count(self::workingDates($from, $to));
    }

    /** Minutos productivos por ingeniero por día. */
    public static function dailyMinutesPerEngineer(?array $cfg = null): int
    {
        $cfg ??= self::config();
        return (int) round($cfg['hours_per_day'] * 60);
    }
}
