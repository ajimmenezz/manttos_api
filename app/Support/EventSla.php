<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventSlaSetting;
use App\Models\EventSlaTier;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Motor de SLA de eventos: resuelve la configuración efectiva (global default u
 * override por cliente), deriva la prioridad de la matriz Impacto×Urgencia, calcula
 * las fechas límite en horario hábil y mide el cumplimiento contra el historial de
 * estados. Toda la config vive en `event_sla_settings` (JSON) — ver [[EventSlaSetting]].
 */
class EventSla
{
    /** Config global cacheada por request. */
    protected static ?array $globalCache = null;

    /** Config por cliente cacheada por request. */
    protected static array $clientCache = [];

    /** Configuración por defecto (semilla + fallback si no hay filas en BD). */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            // Impacto|Urgencia → Prioridad (matriz 3×3 → 4 prioridades).
            'matrix' => [
                'alto|alta'   => 'critica', 'alto|media'  => 'alta',  'alto|baja'  => 'media',
                'medio|alta'  => 'alta',    'medio|media' => 'media', 'medio|baja' => 'baja',
                'bajo|alta'   => 'media',   'bajo|media'  => 'baja',  'bajo|baja'  => 'baja',
            ],
            // Objetivo (horas) por prioridad y nivel de atención; `scheduled` = sin reloj.
            'priorities' => [
                'critica' => ['scheduled' => false, 'targets' => ['remota_n1' => 2, 'en_sitio' => 4,  'especializada' => 8]],
                'alta'    => ['scheduled' => false, 'targets' => ['remota_n1' => 4, 'en_sitio' => 8,  'especializada' => 16]],
                'media'   => ['scheduled' => false, 'targets' => ['remota_n1' => 8, 'en_sitio' => 16, 'especializada' => 32]],
                'baja'    => ['scheduled' => true,  'targets' => []],
            ],
            'calendar' => [
                'mode'      => 'business',            // business | 24_7
                'work_days' => [1, 2, 3, 4, 5],       // 1=lun … 7=dom (ISO)
                'start'     => '08:00',
                'end'       => '18:00',
                // Zona horaria del SERVICIO (no la de la app): las horas hábiles se cuentan
                // en local. Los timestamps de la BD siguen en UTC.
                'timezone'  => 'America/Mexico_City',
                'holidays'  => [],                    // ['2026-12-25', ...]
            ],
        ];
    }

    /** Devuelve la fila cruda de settings (o null) para un cliente / global. */
    protected static function row(?int $clientId): ?EventSlaSetting
    {
        return EventSlaSetting::where('client_id', $clientId)->first();
    }

    /**
     * Configuración EFECTIVA para un cliente: su override si existe, si no el default
     * global de BD, si no los defaults de código. Se completa con defaults para tolerar
     * configs parciales.
     */
    public static function resolve(?int $clientId): array
    {
        if ($clientId === null) {
            if (self::$globalCache !== null) return self::$globalCache;
            $row = self::row(null);
            return self::$globalCache = self::merge(self::defaults(), $row?->only(['enabled', 'matrix', 'priorities', 'calendar']));
        }

        if (array_key_exists($clientId, self::$clientCache)) return self::$clientCache[$clientId];

        $clientRow = self::row($clientId);
        $base = self::resolve(null);
        return self::$clientCache[$clientId] = $clientRow
            ? self::merge($base, $clientRow->only(['enabled', 'matrix', 'priorities', 'calendar']))
            : $base;
    }

    /** Mezcla superficial: los valores presentes en $override reemplazan a los de $base. */
    protected static function merge(array $base, ?array $override): array
    {
        if (! $override) return $base;
        foreach (['enabled', 'matrix', 'priorities', 'calendar'] as $k) {
            if (array_key_exists($k, $override) && $override[$k] !== null) {
                $base[$k] = $override[$k];
            }
        }
        return $base;
    }

    public static function flushCache(): void
    {
        self::$globalCache = null;
        self::$clientCache = [];
    }

    /** Prioridad derivada de la matriz para un (impacto, urgencia). */
    public static function priorityFor(?string $impact, ?string $urgency, array $settings): ?string
    {
        if (! $impact || ! $urgency) return null;
        return $settings['matrix'][$impact . '|' . $urgency] ?? null;
    }

    /** ¿La prioridad usa atención programada (sin reloj de SLA)? */
    public static function isScheduled(string $priority, array $settings): bool
    {
        return (bool) ($settings['priorities'][$priority]['scheduled'] ?? false);
    }

    /**
     * Fecha límite = inicio + N horas contando según el calendario de servicio.
     * En modo business respeta días hábiles, horario y feriados; en 24_7 suma directo.
     */
    public static function deadline(CarbonInterface $start, float $hours, array $calendar): Carbon
    {
        $tz       = $calendar['timezone'] ?? (config('app.timezone') ?: 'UTC');
        $mode     = $calendar['mode'] ?? 'business';
        $workDays = array_map('intval', $calendar['work_days'] ?? [1, 2, 3, 4, 5]);
        $minutes  = (int) round($hours * 60);

        if ($mode === '24_7' || empty($workDays)) {
            return Carbon::instance($start)->copy()->addMinutes($minutes);
        }

        [$sh, $sm] = array_pad(explode(':', $calendar['start'] ?? '08:00'), 2, 0);
        [$eh, $em] = array_pad(explode(':', $calendar['end'] ?? '18:00'), 2, 0);
        $holidays  = array_flip($calendar['holidays'] ?? []);

        $cursor    = Carbon::instance($start)->copy()->setTimezone($tz);
        $remaining = $minutes;
        $guard     = 0;

        while ($guard++ < 4000) {
            $isWorkDay = in_array($cursor->dayOfWeekIso, $workDays, true)
                && ! isset($holidays[$cursor->toDateString()]);

            $dayStart = $cursor->copy()->setTime((int) $sh, (int) $sm, 0);
            $dayEnd   = $cursor->copy()->setTime((int) $eh, (int) $em, 0);

            if (! $isWorkDay || $cursor->getTimestamp() >= $dayEnd->getTimestamp()) {
                $cursor = $cursor->copy()->addDay()->setTime((int) $sh, (int) $sm, 0);
                continue;
            }
            if ($cursor->getTimestamp() < $dayStart->getTimestamp()) {
                $cursor = $dayStart;
            }

            // Minutos hábiles disponibles hoy (timestamp evita ambigüedades de signo de Carbon 3).
            $available = (int) floor(($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $cursor->copy()->addMinutes($remaining);
            }
            $remaining -= $available;
            $cursor = $cursor->copy()->addDay()->setTime((int) $sh, (int) $sm, 0);
        }

        // Salvaguarda: si algo no cuadra, cae a suma directa.
        return Carbon::instance($start)->copy()->addMinutes($minutes);
    }

    /**
     * Mide el cumplimiento de SLA de un evento. Requiere `$event->history` cargado (con
     * to_status_id) y el mapa status_id → tier_id. Devuelve una estructura lista para el
     * front y la reportería.
     *
     * @param  array<int,int|null>       $statusTierMap  status_id → sla_tier_id
     * @param  Collection<int,EventSlaTier> $tiers
     */
    public static function measure(Event $event, array $settings, array $statusTierMap, Collection $tiers, ?CarbonInterface $now = null): array
    {
        $now      = $now ? Carbon::instance($now) : Carbon::now();
        $priority = $event->priority;
        $pConf    = $settings['priorities'][$priority] ?? null;
        $tracked  = ($settings['enabled'] ?? true) && $pConf !== null && $event->impact && $event->urgency;

        if (! $tracked) {
            return ['tracked' => false, 'scheduled' => false, 'tiers' => [], 'overall' => 'untracked'];
        }

        $start = Carbon::instance($event->created_at);

        // Primer momento (del historial) en que el evento entró a un estado de cada nivel.
        $attendedAt = []; // tier_id => Carbon
        foreach ($event->history as $h) {
            $tierId = $statusTierMap[$h->to_status_id] ?? null;
            if ($tierId && ! isset($attendedAt[$tierId])) {
                $attendedAt[$tierId] = Carbon::instance($h->created_at);
            }
        }

        // Atención programada (sin reloj).
        if (! empty($pConf['scheduled'])) {
            $scheduledAt = $event->scheduled_attention_at ? Carbon::instance($event->scheduled_attention_at) : null;
            $done        = ! empty($attendedAt); // llegó a algún nivel de atención
            $overdue     = $scheduledAt && ! $done && $now->greaterThan($scheduledAt);
            return [
                'tracked'      => true,
                'scheduled'    => true,
                'scheduled_at' => $scheduledAt?->toIso8601String(),
                'overall'      => $done ? 'attended' : ($overdue ? 'overdue' : 'scheduled'),
                'tiers'        => [],
            ];
        }

        $rows   = [];
        $states = [];
        foreach ($tiers as $tier) {
            $target = $pConf['targets'][$tier->key] ?? null;
            if ($target === null || $target === '') continue; // nivel no aplica a esta prioridad

            $deadline = self::deadline($start, (float) $target, $settings['calendar']);
            $att      = $attendedAt[$tier->id] ?? null;

            if ($att) {
                $state = $att->lessThanOrEqualTo($deadline) ? 'met' : 'breached';
            } else {
                $state = $now->greaterThan($deadline) ? 'overdue' : 'pending';
            }
            $states[] = $state;

            $rows[] = [
                'tier_id'      => $tier->id,
                'tier_key'     => $tier->key,
                'tier_label'   => $tier->label,
                'target_hours' => (float) $target,
                'deadline'     => $deadline->toIso8601String(),
                'attended_at'  => $att?->toIso8601String(),
                'state'        => $state,
            ];
        }

        return [
            'tracked'   => true,
            'scheduled' => false,
            'overall'   => self::overall($states),
            'tiers'     => $rows,
        ];
    }

    /** Resumen del evento: lo peor manda (vencido/incumplido > pendiente > cumplido). */
    protected static function overall(array $states): string
    {
        if (empty($states)) return 'untracked';
        if (in_array('breached', $states, true)) return 'breached';
        if (in_array('overdue', $states, true))  return 'overdue';
        if (in_array('pending', $states, true))   return 'pending';
        return 'met';
    }

    /** Niveles de atención activos, ordenados. */
    public static function tiers(): Collection
    {
        return EventSlaTier::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }
}
