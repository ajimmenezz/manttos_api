<?php

namespace App\Support;

use App\Models\ReportSectionSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Catálogo de SECCIONES de los reportes-tablero y resolución de cuáles se ocultan
 * a cada usuario.
 *
 * Dos ejes, igual que en el resto del sistema:
 *  - El PERMISO (`events.view`, `maintenances.report`) decide si entras al reporte.
 *  - Esta configuración decide QUÉ BLOQUES ves dentro. No es seguridad de datos: es
 *    recorte de la vista — aunque el backend sí vacía del payload lo oculto, para que
 *    no viaje, no se imprima y no se exporte.
 *
 * Resolución (de menor a mayor precedencia):
 *   1. Todo visible.
 *   2. Rol(es) del usuario: si CUALQUIER rol oculta la sección, queda oculta.
 *   3. Ajuste por usuario: manda sobre el rol en ambos sentidos (puede volver a
 *      mostrar lo que el rol oculta, o esconder lo que el rol muestra).
 *
 * Las llaves son estables: renombrar una es perder la configuración guardada.
 */
class ReportSections
{
    public const REPORTS = ['events', 'maintenances'];

    /**
     * Catálogo por reporte. Cada sección: key, group, label y `payload` = llaves de la
     * respuesta JSON que se vacían cuando está oculta (sin `payload` = la sección sólo
     * vive en el front, como las tarjetas de KPI sueltas, que salen de `summary`).
     */
    public static function catalog(): array
    {
        return [
            'events' => [
                'label'    => 'Reporte de eventos',
                'route'    => '/events/reportes',
                'sections' => [
                    ['key' => 'kpi.total',          'group' => 'Indicadores', 'label' => 'Eventos totales'],
                    ['key' => 'kpi.abiertos',       'group' => 'Indicadores', 'label' => 'Abiertos'],
                    ['key' => 'kpi.resueltos',      'group' => 'Indicadores', 'label' => 'Resueltos'],
                    ['key' => 'kpi.avg_resolution', 'group' => 'Indicadores', 'label' => 'Días promedio de resolución'],

                    ['key' => 'sla.kpi.compliance', 'group' => 'Nivel de servicio (SLA)', 'label' => 'Porcentaje de cumplimiento'],
                    ['key' => 'sla.kpi.met',        'group' => 'Nivel de servicio (SLA)', 'label' => 'En tiempo'],
                    ['key' => 'sla.kpi.breached',   'group' => 'Nivel de servicio (SLA)', 'label' => 'Fuera de SLA'],
                    ['key' => 'sla.kpi.overdue',    'group' => 'Nivel de servicio (SLA)', 'label' => 'Vencidos'],
                    ['key' => 'sla.by_tier',        'group' => 'Nivel de servicio (SLA)', 'label' => 'Cumplimiento por nivel de atención (tabla)'],

                    ['key' => 'weekly',        'group' => 'Gráficas', 'label' => 'Volumen de eventos por semana', 'payload' => ['weekly']],
                    ['key' => 'by_status',     'group' => 'Gráficas', 'label' => 'Por estado',                    'payload' => ['by_status']],
                    ['key' => 'by_priority',   'group' => 'Gráficas', 'label' => 'Por prioridad',                 'payload' => ['by_priority']],
                    ['key' => 'by_nature',     'group' => 'Gráficas', 'label' => 'Incidentes vs. solicitudes',    'payload' => ['by_nature']],
                    ['key' => 'by_event_type', 'group' => 'Gráficas', 'label' => 'Por tipo de evento',            'payload' => ['by_event_type']],
                    ['key' => 'by_impact',     'group' => 'Gráficas', 'label' => 'Por impacto',                   'payload' => ['by_impact']],
                    ['key' => 'by_urgency',    'group' => 'Gráficas', 'label' => 'Por urgencia',                  'payload' => ['by_urgency']],

                    ['key' => 'form_breakdowns',      'group' => 'KPIs dinámicos', 'label' => 'Campos del formulario', 'payload' => ['form_breakdowns', 'form_meta']],
                    ['key' => 'directory_breakdowns', 'group' => 'KPIs dinámicos', 'label' => 'Datos del directorio',  'payload' => ['directory_breakdowns', 'directory_meta']],

                    ['key' => 'rank_system', 'group' => 'Tablas', 'label' => 'Ranking por sistema', 'payload' => ['by_system']],
                    ['key' => 'rank_client', 'group' => 'Tablas', 'label' => 'Ranking por cliente', 'payload' => ['by_client']],
                    ['key' => 'rank_site',   'group' => 'Tablas', 'label' => 'Ranking por sitio',   'payload' => ['by_site']],
                    ['key' => 'detail',      'group' => 'Tablas', 'label' => 'Detalle de eventos (tabla y descarga a Excel)'],

                    ['key' => 'plan_view',   'group' => 'Vistas', 'label' => 'Vista de plano (dispositivos con eventos)'],
                ],
            ],
            'maintenances' => [
                'label'    => 'Reporte de mantenimientos',
                'route'    => '/maintenances/reportes',
                'sections' => [
                    ['key' => 'kpi.total',     'group' => 'Indicadores', 'label' => 'Capturas de actividad'],
                    ['key' => 'kpi.devices',   'group' => 'Indicadores', 'label' => 'Dispositivos atendidos'],
                    ['key' => 'kpi.engineers', 'group' => 'Indicadores', 'label' => 'Ingenieros'],
                    ['key' => 'kpi.sites',     'group' => 'Indicadores', 'label' => 'Sitios'],

                    ['key' => 'weekly',                'group' => 'Gráficas', 'label' => 'Capturas por semana',          'payload' => ['weekly']],
                    ['key' => 'by_hour',               'group' => 'Gráficas', 'label' => 'Horarios de captura',          'payload' => ['by_hour']],
                    ['key' => 'by_activity_type',      'group' => 'Gráficas', 'label' => 'Por tipo de actividad',        'payload' => ['by_activity_type']],
                    ['key' => 'by_maintenance_status', 'group' => 'Gráficas', 'label' => 'Por estado del mantenimiento', 'payload' => ['by_maintenance_status']],
                    ['key' => 'by_system',             'group' => 'Gráficas', 'label' => 'Por sistema',                  'payload' => ['by_system']],

                    ['key' => 'form_breakdowns',      'group' => 'KPIs dinámicos', 'label' => 'Campos del formulario', 'payload' => ['form_breakdowns']],
                    ['key' => 'directory_breakdowns', 'group' => 'KPIs dinámicos', 'label' => 'Datos del directorio',  'payload' => ['directory_breakdowns']],

                    ['key' => 'rank_engineer', 'group' => 'Tablas', 'label' => 'Ranking por ingeniero', 'payload' => ['by_engineer']],
                    ['key' => 'rank_client',   'group' => 'Tablas', 'label' => 'Ranking por cliente',   'payload' => ['by_client']],
                    ['key' => 'rank_site',     'group' => 'Tablas', 'label' => 'Ranking por sitio',     'payload' => ['by_site']],
                    ['key' => 'detail',        'group' => 'Tablas', 'label' => 'Detalle de capturas (tabla y descarga a Excel)'],
                ],
            ],
        ];
    }

    public static function assertReport(string $report): void
    {
        abort_unless(in_array($report, self::REPORTS, true), 422, 'Reporte desconocido.');
    }

    /** Llaves válidas de un reporte. */
    public static function keys(string $report): array
    {
        return array_column(self::catalog()[$report]['sections'] ?? [], 'key');
    }

    /**
     * Secciones ocultas para un usuario. El superadmin también respeta la configuración
     * (así previsualiza lo que ve cada quien); la pantalla que la edita nunca se oculta.
     */
    public static function hiddenFor(User $user, string $report): array
    {
        $valid  = self::keys($report);
        $hidden = [];

        $roleIds = $user->roles()->pluck('roles.id')->all();

        if ($roleIds) {
            $rows = ReportSectionSetting::where('report', $report)
                ->where('scope_type', 'role')
                ->whereIn('scope_id', $roleIds)
                ->get();

            foreach ($rows as $row) {
                foreach ((array) $row->overrides as $key => $visible) {
                    if (! $visible) $hidden[$key] = true;   // basta que UN rol la oculte
                }
            }
        }

        $own = ReportSectionSetting::where('report', $report)
            ->where('scope_type', 'user')
            ->where('scope_id', $user->id)
            ->first();

        foreach ((array) ($own->overrides ?? []) as $key => $visible) {
            if ($visible) unset($hidden[$key]); else $hidden[$key] = true;
        }

        return array_values(array_intersect(array_keys($hidden), $valid));
    }

    public static function isHidden(User $user, string $report, string $key): bool
    {
        return in_array($key, self::hiddenFor($user, $report), true);
    }

    /**
     * Vacía del payload las llaves de las secciones ocultas y anexa `hidden_sections`
     * para que el front recorte también lo que sólo existe ahí (tarjetas de KPI).
     */
    public static function stripPayload(array $payload, string $report, array $hidden): array
    {
        $byKey = collect(self::catalog()[$report]['sections'] ?? [])->keyBy('key');

        foreach ($hidden as $key) {
            foreach ($byKey[$key]['payload'] ?? [] as $field) {
                if (! array_key_exists($field, $payload)) continue;

                $current = $payload[$field];
                $payload[$field] = (is_array($current) || $current instanceof Collection) ? [] : null;
            }
        }

        $payload['hidden_sections'] = $hidden;

        return $payload;
    }
}
