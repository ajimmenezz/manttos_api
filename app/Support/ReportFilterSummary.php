<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

/**
 * «Filtros aplicados»: qué recorte de datos representa un reporte impreso.
 *
 * Un PDF de reporte se archiva y se manda por correo, y meses después nadie recuerda si
 * aquellas 135 incidencias eran de un sitio, de un cliente o de todo el universo. Sin
 * esta tabla el documento es ambiguo justo donde importa.
 *
 * Traduce los parámetros de la petición a lenguaje humano usando **las mismas listas de
 * opciones que ya viajan en el payload del reporte** (`filters`), así que un cliente o un
 * estado se imprimen por su nombre y no como `client_id=5`. Si un id no está en esas
 * listas —porque el filtro dejó fuera todo lo demás— se imprime el valor crudo antes que
 * omitir el filtro: es peor un resumen incompleto que uno feo.
 */
class ReportFilterSummary
{
    /** @return array<int, array{0:string,1:string}> pares [etiqueta, valor] */
    public static function rows(Request $request, array $payload, string $report): array
    {
        $filters = $payload['filters'] ?? [];
        $rows    = [];

        if ($period = self::period($request)) $rows[] = ['Periodo', $period];

        $rows = array_merge($rows, match ($report) {
            'personnel'  => self::personnel($request, $filters),
            'events'     => array_merge(self::common($request, $filters), self::events($request, $filters)),
            default      => array_merge(self::common($request, $filters), self::maintenances($request, $filters)),
        });

        // Campos dinámicos: del formulario y del directorio.
        $rows = array_merge($rows, self::dynamic($request->input('field_filters'), $filters['fields'] ?? []));
        $rows = array_merge($rows, self::dynamic($request->input('dir_filters'), $filters['dir_fields'] ?? []));

        return $rows;
    }

    private static function period(Request $request): ?string
    {
        $from = self::date($request->input('date_from'));
        $to   = self::date($request->input('date_to'));

        return match (true) {
            $from && $to => "{$from} a {$to}",
            (bool) $from => "desde {$from}",
            (bool) $to   => "hasta {$to}",
            default      => null,
        };
    }

    private static function common(Request $request, array $filters): array
    {
        return array_values(array_filter([
            self::pick($request, 'client_id', 'Cliente', $filters['clients'] ?? [], 'name'),
            self::pick($request, 'site_id',   'Sitio',   $filters['sites'] ?? [],   'name'),
            self::pick($request, 'system_id', 'Sistema', $filters['systems'] ?? [], 'label'),
        ]));
    }

    /**
     * Análisis de personal: no lleva cliente ni sitio —es un reporte interno de gestión—,
     * pero sí a quiénes se está mirando, que es lo que da sentido a las cifras.
     */
    private static function personnel(Request $request, array $filters): array
    {
        $rows = [];
        $ids  = array_filter(array_map('trim', explode(',', (string) $request->input('user_ids'))));

        if ($ids) {
            $names = collect($filters['users'] ?? [])
                ->whereIn('id', array_map('intval', $ids))
                ->pluck('name')
                ->all();

            $rows[] = ['Personas', $names ? implode(', ', $names) : implode(', ', $ids)];
        }

        if ($role = $request->input('role')) $rows[] = ['Rol', (string) $role];

        // Sin filtro no es "ninguno": es todo el personal, y conviene decirlo.
        if (! $ids && ! $role) $rows[] = ['Personas', 'Todo el personal'];

        return $rows;
    }

    private static function events(Request $request, array $filters): array
    {
        return array_values(array_filter([
            self::pick($request, 'event_type_id', 'Tipo de evento', $filters['types'] ?? [],    'label'),
            self::pick($request, 'status_id',     'Estado',         $filters['statuses'] ?? [], 'label'),
            self::literal($request, 'priority', 'Prioridad', ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica']),
            self::literal($request, 'nature',   'Naturaleza', ['incidente' => 'Incidente', 'solicitud' => 'Solicitud']),
        ]));
    }

    private static function maintenances(Request $request, array $filters): array
    {
        return array_values(array_filter([
            self::pick($request, 'activity_type_id', 'Tipo de actividad', $filters['activityTypes'] ?? [], 'label'),
            self::pick($request, 'engineer_id',      'Ingeniero',         $filters['engineers'] ?? [],     'name'),
            // Estos dos se filtran por su clave («programado»), no por id.
            self::pickBy($request, 'maintenance_status', 'Estado del mantenimiento', $filters['statuses'] ?? [], 'value', 'label'),
            self::pickBy($request, 'maintenance_type',   'Tipo de mantenimiento',    $filters['types'] ?? [],    'value', 'label'),
        ]));
    }

    /** Un filtro por id, resuelto contra la lista de opciones del propio reporte. */
    private static function pick(Request $request, string $param, string $label, array $options, string $field): ?array
    {
        return self::pickBy($request, $param, $label, $options, 'id', $field);
    }

    private static function pickBy(Request $request, string $param, string $label, array $options, string $key, string $field): ?array
    {
        $value = $request->input($param);
        if ($value === null || $value === '') return null;

        foreach ($options as $o) {
            if ((string) ($o[$key] ?? '') === (string) $value) {
                return [$label, (string) ($o[$field] ?? $value)];
            }
        }

        return [$label, (string) $value];
    }

    private static function literal(Request $request, string $param, string $label, array $map): ?array
    {
        $value = $request->input($param);
        if ($value === null || $value === '') return null;

        return [$label, $map[$value] ?? (string) $value];
    }

    /**
     * Filtros por campo (formulario o directorio). Llegan como JSON
     * `{ "<llave>": {"value": …} | {"min": …, "max": …} }` y la llave sólo es legible
     * a través del encabezado que ya publica el reporte.
     */
    private static function dynamic(mixed $json, array $options): array
    {
        if (! is_string($json) || trim($json) === '') return [];

        try {
            $parsed = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];   // un filtro ilegible no debe tumbar el reporte
        }

        if (! is_array($parsed)) return [];

        $headers = [];
        foreach ($options as $o) {
            if (isset($o['key'])) $headers[$o['key']] = $o['header'] ?? $o['key'];
        }

        $rows = [];

        foreach ($parsed as $key => $cond) {
            if (! is_array($cond)) continue;

            $label = $headers[$key] ?? $key;
            $value = match (true) {
                isset($cond['value']) => (! empty($cond['contains']) ? 'contiene ' : '') . self::scalar($cond['value']),
                isset($cond['min'], $cond['max']) => "entre {$cond['min']} y {$cond['max']}",
                isset($cond['min']) => "desde {$cond['min']}",
                isset($cond['max']) => "hasta {$cond['max']}",
                default => null,
            };

            if ($value !== null && $value !== '') $rows[] = [$label, $value];
        }

        return $rows;
    }

    private static function scalar(mixed $v): string
    {
        if (is_bool($v)) return $v ? 'Sí' : 'No';
        if (is_array($v)) return implode(', ', array_filter($v, 'is_scalar'));

        return (string) $v;
    }

    private static function date(mixed $d): ?string
    {
        if (! $d) return null;

        try {
            return Carbon::parse((string) $d)->format('d/m/Y');
        } catch (Throwable) {
            return (string) $d;
        }
    }
}
