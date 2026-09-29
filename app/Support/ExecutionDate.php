<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Fecha y hora de ejecución (actividades, `performed_at`) y de ocurrencia (eventos,
 * `occurred_at`).
 *
 * Los clientes capturan en hora LOCAL y mandan el instante con su desfase
 * ('2026-09-25T14:30:00-06:00'); aquí se pasa a la zona de la app (UTC), que es como
 * se guarda todo lo demás (`created_at`, `now()`).
 *
 * Hasta 2026-09 los formularios mandaban SOLO la fecha ('2026-09-25') y se guardaba a
 * las 00:00 UTC: la bitácora decía «00:00» —o las 18:00 del día anterior en pantallas
 * que convierten a hora local—. Esa forma se sigue aceptando porque los APK viejos y
 * sus pendientes sin conexión la siguen mandando: si la fecha es hoy se toma el momento
 * de recepción; si es otro día, la medianoche LOCAL de ese día (la hora no se conoce).
 */
class ExecutionDate
{
    public const TZ = WorkCalendar::TZ;

    /** Margen para relojes de teléfono un poco adelantados. */
    private const FUTURE_TOLERANCE_MINUTES = 5;

    /**
     * El instante que se guarda, en la zona de la app; null si no se puede usar
     * (vacío, ilegible o futuro) y el llamador aplica su valor por omisión.
     */
    public static function parse(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                // Forma vieja: solo la fecha, entendida como día LOCAL.
                $day   = Carbon::createFromFormat('Y-m-d', $value, self::TZ)->startOfDay();
                $today = Carbon::now(self::TZ)->startOfDay();
                if ($day->gt($today)) return null;
                return $day->eq($today) ? now() : self::toAppZone($day);
            }

            // Con hora. Si trae desfase se respeta; si no, se entiende en hora local.
            $at = Carbon::parse($value, self::TZ);
        } catch (\Throwable) {
            return null;
        }

        if ($at->gt(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) return null;

        return self::toAppZone($at);
    }

    /** Inicio del día LOCAL 'Y-m-d', en la zona de la app (para filtros por fecha). */
    public static function dayStart(string $date): Carbon
    {
        return self::toAppZone(Carbon::parse($date, self::TZ)->startOfDay());
    }

    /** Inicio del día LOCAL siguiente a 'Y-m-d' (límite exclusivo del filtro «hasta»). */
    public static function dayEndExclusive(string $date): Carbon
    {
        return self::toAppZone(Carbon::parse($date, self::TZ)->startOfDay()->addDay());
    }

    /** Último instante del día LOCAL 'Y-m-d', en la zona de la app (para filtros «hasta» inclusivos). */
    public static function dayEnd(string $date): Carbon
    {
        return self::dayEndExclusive($date)->subMicrosecond();
    }

    /** Un instante guardado, visto en hora local (PDF, textos del servidor). */
    public static function local(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') return null;
        return Carbon::parse($value, config('app.timezone'))->setTimezone(self::TZ);
    }

    private static function toAppZone(CarbonInterface $at): Carbon
    {
        // Eloquent guarda el Carbon tal cual lo recibe, SIN convertir de zona: si llegara
        // en -06:00 se escribiría la hora local como si fuera UTC.
        return Carbon::instance($at)->setTimezone(config('app.timezone'));
    }
}
