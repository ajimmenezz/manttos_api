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
    public const REPORTS = ['events', 'maintenances', 'personnel'];

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
                    ['key' => 'kpi.total',          'group' => 'Indicadores', 'label' => 'Eventos totales', 'pdf' => true],
                    ['key' => 'kpi.abiertos',       'group' => 'Indicadores', 'label' => 'Abiertos', 'pdf' => true],
                    ['key' => 'kpi.resueltos',      'group' => 'Indicadores', 'label' => 'Resueltos', 'pdf' => true],
                    ['key' => 'kpi.avg_resolution', 'group' => 'Indicadores', 'label' => 'Días promedio de resolución', 'pdf' => true],

                    ['key' => 'sla.kpi.compliance', 'group' => 'Nivel de servicio (SLA)', 'label' => 'Porcentaje de cumplimiento'],
                    ['key' => 'sla.kpi.met',        'group' => 'Nivel de servicio (SLA)', 'label' => 'En tiempo'],
                    ['key' => 'sla.kpi.breached',   'group' => 'Nivel de servicio (SLA)', 'label' => 'Fuera de SLA'],
                    ['key' => 'sla.kpi.overdue',    'group' => 'Nivel de servicio (SLA)', 'label' => 'Vencidos'],
                    ['key' => 'sla.by_tier',        'group' => 'Nivel de servicio (SLA)', 'label' => 'Cumplimiento por nivel de atención (tabla)', 'pdf' => true],

                    ['key' => 'weekly',        'group' => 'Gráficas', 'label' => 'Volumen de eventos por semana', 'payload' => ['weekly'], 'pdf' => true],
                    ['key' => 'by_status',     'group' => 'Gráficas', 'label' => 'Por estado',                    'payload' => ['by_status'], 'pdf' => true],
                    ['key' => 'by_priority',   'group' => 'Gráficas', 'label' => 'Por prioridad',                 'payload' => ['by_priority'], 'pdf' => true],
                    ['key' => 'by_nature',     'group' => 'Gráficas', 'label' => 'Incidentes vs. solicitudes',    'payload' => ['by_nature'], 'pdf' => true],
                    ['key' => 'by_event_type', 'group' => 'Gráficas', 'label' => 'Por tipo de evento',            'payload' => ['by_event_type'], 'pdf' => true],
                    ['key' => 'by_impact',     'group' => 'Gráficas', 'label' => 'Por impacto',                   'payload' => ['by_impact'], 'pdf' => true],
                    ['key' => 'by_urgency',    'group' => 'Gráficas', 'label' => 'Por urgencia',                  'payload' => ['by_urgency'], 'pdf' => true],

                    ['key' => 'form_breakdowns',      'group' => 'KPIs dinámicos', 'label' => 'Campos del formulario', 'payload' => ['form_breakdowns', 'form_meta']],
                    ['key' => 'directory_breakdowns', 'group' => 'KPIs dinámicos', 'label' => 'Datos del directorio',  'payload' => ['directory_breakdowns', 'directory_meta']],

                    ['key' => 'rank_system', 'group' => 'Tablas', 'label' => 'Ranking por sistema', 'payload' => ['by_system'], 'pdf' => true],
                    ['key' => 'rank_client', 'group' => 'Tablas', 'label' => 'Ranking por cliente', 'payload' => ['by_client'], 'pdf' => true],
                    ['key' => 'rank_site',   'group' => 'Tablas', 'label' => 'Ranking por sitio',   'payload' => ['by_site'], 'pdf' => true],
                    ['key' => 'detail',      'group' => 'Tablas', 'label' => 'Detalle de eventos (tabla y descarga a Excel)'],

                    ['key' => 'plan_view',   'group' => 'Vistas', 'label' => 'Vista de plano (dispositivos con eventos)'],

                    ['key' => 'filters_applied', 'group' => 'Documento', 'label' => 'Resumen de filtros aplicados (sólo impreso)', 'pdf' => true],
                ],
            ],
            'personnel' => [
                'label'    => 'Análisis de personal',
                'route'    => '/reports/personal',
                'sections' => [
                    ['key' => 'kpi.people',     'group' => 'Indicadores', 'label' => 'Personas con actividad', 'pdf' => true],
                    ['key' => 'kpi.records',    'group' => 'Indicadores', 'label' => 'Registros en el periodo', 'pdf' => true],
                    ['key' => 'kpi.activities', 'group' => 'Indicadores', 'label' => 'Capturas de actividad',   'pdf' => true],
                    ['key' => 'kpi.events',     'group' => 'Indicadores', 'label' => 'Eventos creados',         'pdf' => true],

                    ['key' => 'weekly',     'group' => 'Gráficas', 'label' => 'Registros por semana',   'payload' => ['weekly'],     'pdf' => true],
                    ['key' => 'by_hour',    'group' => 'Gráficas', 'label' => 'Horarios de trabajo',    'payload' => ['by_hour'],    'pdf' => true],
                    ['key' => 'by_weekday', 'group' => 'Gráficas', 'label' => 'Por día de la semana',   'payload' => ['by_weekday'], 'pdf' => true],

                    ['key' => 'by_activity_type', 'group' => 'Qué hace', 'label' => 'Qué actividades registra',        'payload' => ['by_activity_type'], 'pdf' => true],
                    ['key' => 'by_event_type',    'group' => 'Qué hace', 'label' => 'Qué eventos levanta',             'payload' => ['by_event_type'],    'pdf' => true],
                    ['key' => 'by_module',        'group' => 'Qué hace', 'label' => 'En qué parte del sistema trabaja', 'payload' => ['by_module'],        'pdf' => true],
                    ['key' => 'by_action',        'group' => 'Qué hace', 'label' => 'Qué tipo de acciones hace',       'payload' => ['by_action'],        'pdf' => true],

                    ['key' => 'by_client', 'group' => 'Dónde', 'label' => 'Para qué clientes', 'payload' => ['by_client'], 'pdf' => true],
                    ['key' => 'by_site',   'group' => 'Dónde', 'label' => 'En qué sitios',     'payload' => ['by_site'],   'pdf' => true],

                    ['key' => 'by_person', 'group' => 'Tablas', 'label' => 'Detalle por persona', 'payload' => ['by_person'], 'pdf' => true],

                    ['key' => 'filters_applied', 'group' => 'Documento', 'label' => 'Resumen de filtros aplicados (sólo impreso)', 'pdf' => true],
                ],
            ],
            'maintenances' => [
                'label'    => 'Reporte de mantenimientos',
                'route'    => '/maintenances/reportes',
                'sections' => [
                    ['key' => 'kpi.total',     'group' => 'Indicadores', 'label' => 'Capturas de actividad', 'pdf' => true],
                    ['key' => 'kpi.devices',   'group' => 'Indicadores', 'label' => 'Dispositivos atendidos', 'pdf' => true],
                    ['key' => 'kpi.engineers', 'group' => 'Indicadores', 'label' => 'Ingenieros', 'pdf' => true],
                    ['key' => 'kpi.sites',     'group' => 'Indicadores', 'label' => 'Sitios', 'pdf' => true],

                    ['key' => 'weekly',                'group' => 'Gráficas', 'label' => 'Capturas por semana',          'payload' => ['weekly'], 'pdf' => true],
                    ['key' => 'by_hour',               'group' => 'Gráficas', 'label' => 'Horarios de captura',          'payload' => ['by_hour'], 'pdf' => true],
                    ['key' => 'by_activity_type',      'group' => 'Gráficas', 'label' => 'Por tipo de actividad',        'payload' => ['by_activity_type'], 'pdf' => true],
                    ['key' => 'by_maintenance_status', 'group' => 'Gráficas', 'label' => 'Por estado del mantenimiento', 'payload' => ['by_maintenance_status'], 'pdf' => true],
                    ['key' => 'by_system',             'group' => 'Gráficas', 'label' => 'Por sistema',                  'payload' => ['by_system'], 'pdf' => true],

                    ['key' => 'form_breakdowns',      'group' => 'KPIs dinámicos', 'label' => 'Campos del formulario', 'payload' => ['form_breakdowns']],
                    ['key' => 'directory_breakdowns', 'group' => 'KPIs dinámicos', 'label' => 'Datos del directorio',  'payload' => ['directory_breakdowns']],

                    ['key' => 'rank_engineer', 'group' => 'Tablas', 'label' => 'Ranking por ingeniero', 'payload' => ['by_engineer'], 'pdf' => true],
                    ['key' => 'rank_client',   'group' => 'Tablas', 'label' => 'Ranking por cliente',   'payload' => ['by_client'], 'pdf' => true],
                    ['key' => 'rank_site',     'group' => 'Tablas', 'label' => 'Ranking por sitio',     'payload' => ['by_site'], 'pdf' => true],
                    ['key' => 'detail',        'group' => 'Tablas', 'label' => 'Detalle de capturas (tabla y descarga a Excel)'],

                    ['key' => 'filters_applied', 'group' => 'Documento', 'label' => 'Resumen de filtros aplicados (sólo impreso)', 'pdf' => true],
                ],
            ],
        ];
    }

    /**
     * Secciones que el PDF sabe dibujar y que este usuario puede ver.
     *
     * Alimenta el selector de «qué se imprime» del botón de descarga. La marca `pdf`
     * vive en el catálogo —y no en una lista aparte— para que agregar una gráfica al
     * imprimible sea un solo cambio: si no se marca, no aparece como opción y tampoco
     * se dibuja, en vez de ofrecerse y salir en blanco.
     *
     * @param array $hidden llaves recortadas por rol/usuario
     */
    public static function printable(string $report, array $hidden = []): array
    {
        self::assertReport($report);

        return array_values(array_map(
            fn ($s) => ['key' => $s['key'], 'group' => $s['group'], 'label' => $s['label']],
            array_filter(
                self::catalog()[$report]['sections'],
                fn ($s) => ($s['pdf'] ?? false) && ! in_array($s['key'], $hidden, true),
            ),
        ));
    }

    /**
     * ¿Se imprime esta sección?
     *
     * Manda primero el recorte por rol/usuario —nadie imprime lo que no puede ver— y
     * encima la selección puntual de la descarga. Sin selección se imprime todo lo
     * visible, que es el comportamiento de siempre.
     */
    public static function printFilter(array $hidden, mixed $selected): callable
    {
        $selected = is_string($selected)
            ? array_filter(array_map('trim', explode(',', $selected)))
            : (is_array($selected) ? $selected : []);

        return fn (string $key) => ! in_array($key, $hidden, true)
            && ($selected === [] || in_array($key, $selected, true));
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
